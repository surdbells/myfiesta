import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import {
  ToastStore,
  UiButton,
  UiConfirm,
  UiErrorState,
  UiIcon,
  UiSkeleton,
} from '@myfiesta/ui';
import { ImagePlus, Star, Trash2, Upload } from 'lucide-angular';
import { Api } from '../../core/api';
import { EventImage, EventImages, UploadProgress } from '../../core/api.types';

/** One file on its way up, with enough to draw a row for it. */
interface Upload {
  readonly id: string;
  readonly name: string;
  readonly kind: 'banner' | 'gallery';
  /** Null while the total size is unknown. */
  percent: number | null;
  failed: boolean;
  error?: string;
}

/**
 * The flyer, and the pictures from the last one.
 *
 * Two jobs on one screen because they are two halves of the same decision. The
 * banner is what appears when somebody pastes the link into a group chat — it
 * is the single highest-leverage image on the platform, and it was previously
 * uploaded through a bare file input that said nothing between choosing a file
 * and the page changing.
 *
 * That silence is the thing this screen exists to fix. A club flyer off a
 * phone is three to eight megabytes; on the upload half of a domestic
 * connection that is ten to thirty seconds of nothing. People conclude it did
 * not work and press the button again, which is how the same flyer ends up in
 * a gallery four times.
 */
@Component({
  selector: 'app-event-pictures',
  imports: [UiButton, UiIcon, UiConfirm, UiErrorState, UiSkeleton],
  templateUrl: './event-pictures.html',
  styleUrl: './event-pictures.css',
})
export class EventPictures {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly route = inject(ActivatedRoute);

  protected readonly uploadIcon = Upload;
  protected readonly addIcon = ImagePlus;
  protected readonly deleteIcon = Trash2;
  protected readonly promoteIcon = Star;

  readonly eventId = eventIdFrom(this.route);

  readonly images = signal<EventImages | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);

  /** In-flight uploads, newest first. Cleared as each one lands. */
  readonly uploads = signal<Upload[]>([]);

  readonly removing = signal<EventImage | null>(null);

  /** Whether a file is being dragged over the drop zone. */
  readonly draggingBanner = signal(false);
  readonly draggingGallery = signal(false);

  readonly banner = computed(() => this.images()?.banner ?? null);
  readonly gallery = computed(() => this.images()?.gallery ?? []);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.images(this.eventId).subscribe({
      next: (images) => {
        this.images.set(images);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.failed.set(true);
      },
    });
  }

  // --- choosing files ------------------------------------------------------

  onPicked(event: Event, kind: 'banner' | 'gallery'): void {
    const input = event.target as HTMLInputElement;

    this.accept(input.files, kind);

    // Cleared so choosing the same file twice in a row still fires a change.
    input.value = '';
  }

  onDropped(event: DragEvent, kind: 'banner' | 'gallery'): void {
    event.preventDefault();
    this.setDragging(kind, false);
    this.accept(event.dataTransfer?.files ?? null, kind);
  }

  onDragOver(event: DragEvent, kind: 'banner' | 'gallery'): void {
    event.preventDefault();
    this.setDragging(kind, true);
  }

  setDragging(kind: 'banner' | 'gallery', dragging: boolean): void {
    if (kind === 'banner') this.draggingBanner.set(dragging);
    else this.draggingGallery.set(dragging);
  }

  /**
   * Start uploading whatever was chosen.
   *
   * The banner takes one file and the gallery takes many, so a drop of six
   * images onto the banner uses the first and says so rather than silently
   * discarding five.
   */
  private accept(files: FileList | null, kind: 'banner' | 'gallery'): void {
    if (!files || files.length === 0) return;

    const chosen = kind === 'banner' ? [files[0]] : Array.from(files);

    if (kind === 'banner' && files.length > 1) {
      this.toasts.show('An event has one banner. Using the first image.', 'info');
    }

    for (const file of chosen) {
      const rejection = this.reject(file);

      if (rejection) {
        this.toasts.show(rejection, 'danger');

        continue;
      }

      this.upload(file, kind);
    }
  }

  /**
   * Refused here rather than after a minute of upload.
   *
   * The server checks all of this too and has to. Checking it again in the
   * browser is not duplication for its own sake: it is the difference between
   * being told immediately and being told after sending eleven megabytes.
   */
  private reject(file: File): string | null {
    if (!file.type.startsWith('image/')) {
      return `${file.name} is not an image.`;
    }

    const limit = 10 * 1024 * 1024;

    if (file.size > limit) {
      const megabytes = (file.size / 1024 / 1024).toFixed(1);

      return `${file.name} is ${megabytes} MB. The limit is 10 MB — try exporting it smaller.`;
    }

    return null;
  }

  private upload(file: File, kind: 'banner' | 'gallery'): void {
    const id = `${file.name}-${Date.now()}-${Math.random()}`;

    this.uploads.update((list) => [
      { id, name: file.name, kind, percent: 0, failed: false },
      ...list,
    ]);

    this.api.uploadImage(this.eventId, file, kind).subscribe({
      next: (event) => {
        if ((event as UploadProgress).uploading) {
          this.patch(id, { percent: (event as UploadProgress).percent });

          return;
        }

        // Finished. The row goes and the real image takes its place.
        this.uploads.update((list) => list.filter((u) => u.id !== id));
        this.toasts.show(kind === 'banner' ? 'Banner updated.' : `${file.name} added.`);
        this.load();
      },
      error: (error) => {
        // Kept on screen rather than dismissed. A failed upload that vanishes
        // is one somebody assumes worked.
        this.patch(id, {
          failed: true,
          error: error?.error?.message ?? 'That did not upload. Try again.',
        });
      },
    });
  }

  private patch(id: string, changes: Partial<Upload>): void {
    this.uploads.update((list) =>
      list.map((upload) => (upload.id === id ? { ...upload, ...changes } : upload)),
    );
  }

  dismissUpload(id: string): void {
    this.uploads.update((list) => list.filter((upload) => upload.id !== id));
  }

  readonly bannerUploads = computed(() => this.uploads().filter((u) => u.kind === 'banner'));
  readonly galleryUploads = computed(() => this.uploads().filter((u) => u.kind === 'gallery'));

  // --- managing what is already there --------------------------------------

  /**
   * Make a gallery picture the banner.
   *
   * Organizers upload the flyer into the gallery by mistake constantly — it is
   * the bigger drop zone — and the fix should not be delete, find the file
   * again, re-upload.
   */
  promote(image: EventImage): void {
    this.api.setBanner(this.eventId, image.id).subscribe({
      next: () => {
        this.toasts.show('Banner updated.');
        this.load();
      },
      error: () => this.toasts.show('That could not be made the banner.', 'danger'),
    });
  }

  confirmRemove(): void {
    const image = this.removing();

    if (!image) return;

    this.api.deleteImage(this.eventId, image.id).subscribe({
      next: () => {
        this.removing.set(null);
        this.toasts.show('Picture removed.');
        this.load();
      },
      error: () => {
        this.removing.set(null);
        this.toasts.show('That could not be removed.', 'danger');
      },
    });
  }

  consequence(image: EventImage): string {
    return image.kind === 'banner'
      ? 'This is the picture people see when your link is shared. Without one the event page shows a placeholder.'
      : 'It is removed from the gallery on the event page.';
  }
}
