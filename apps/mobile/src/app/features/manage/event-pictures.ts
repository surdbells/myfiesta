import { Component, ElementRef, OnInit, inject, input, signal, viewChild } from '@angular/core';
import { ArrowLeft, ArrowRight, ImagePlus, MessageSquareText, RectangleHorizontal, Trash2 } from 'lucide-angular';
import type { EventImage, EventImages, OrganizerEventDetail } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { messageOf } from '../../core/errors';
import { Dialogs, MfButton, MfCard, MfEmpty, MfIcon, MfImagePick, MfScreen, MfSkeleton, ToastStore } from '../../ui';
import { EventContext } from './event-context';

const LIMIT = 10 * 1024 * 1024;

interface Upload {
  id: number;
  name: string;
  percent: number | null;
}

/**
 * The event's poster and its gallery.
 *
 * The poster is the wide picture at the top of the event page and on every
 * share; the gallery is what people scroll through. Pictures come from the
 * camera or the library through the phone's own picker, and upload one at a
 * time so a bad signal loses one picture rather than all of them.
 */
@Component({
  selector: 'mf-event-pictures',
  imports: [MfScreen, MfCard, MfButton, MfEmpty, MfSkeleton, MfImagePick, MfIcon],
  template: `
    <mf-screen title="Poster and gallery" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      @if (error(); as message) {
        <mf-empty title="Could not load the pictures" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (images(); as all) {
        <section class="block">
          <h2 class="heading">Poster</h2>
          <p class="note">Wide, at the top of the event page and on every link shared. 1600 × 840 looks sharp.</p>
          <mf-image-pick
            ratio="40 / 21"
            label="Add a poster"
            hint="Up to 10 MB"
            removable
            [src]="all.banner?.display_url ?? null"
            [busy]="posterBusy()"
            [progress]="posterProgress()"
            (chosen)="uploadPoster($event)"
            (removed)="removePoster(all.banner!)"
          />
        </section>

        <section class="block">
          <div class="heading-row">
            <h2 class="heading">Gallery</h2>
            <button mfButton size="sm" variant="secondary" (click)="pick()"><mf-icon [icon]="addIcon" size="sm" /> Add</button>
          </div>
          <p class="note">Tap a picture to caption it, move it, or make it the poster.</p>

          <ul class="grid">
            @for (image of all.gallery; track image.id; let i = $index; let last = $last) {
              <li>
                <button type="button" class="shot" (click)="imageMenu(image, i === 0, last)">
                  <img [src]="image.thumb_url" [alt]="image.caption ?? ''" loading="lazy" />
                  @if (image.caption) {
                    <span class="caption">{{ image.caption }}</span>
                  }
                </button>
              </li>
            }
            @for (upload of uploads(); track upload.id) {
              <li>
                <div class="shot pending" role="progressbar" [attr.aria-label]="'Uploading ' + upload.name" [attr.aria-valuenow]="upload.percent">
                  <span class="bar"><span [style.width.%]="upload.percent ?? 100"></span></span>
                </div>
              </li>
            }
            <li>
              <button type="button" class="shot add" (click)="pick()">
                <mf-icon [icon]="addIcon" size="lg" />
                <span>Add pictures</span>
              </button>
            </li>
          </ul>
        </section>
      } @else {
        <mf-card><mf-skeleton height="10rem" /></mf-card>
      }

      <input #files class="file" type="file" accept="image/jpeg,image/png,image/webp,image/heic" multiple (change)="picked($event)" />
    </mf-screen>
  `,
  styles: `
    .block {
      display: grid;
      gap: var(--space-3);
      margin-bottom: var(--space-6);
    }

    .heading {
      font-size: var(--font-size-lg);
    }

    .heading-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .note {
      margin-top: calc(var(--space-2) * -1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-2);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .shot {
      position: relative;
      display: grid;
      place-items: center;
      width: 100%;
      aspect-ratio: 1;
      padding: 0;
      border: 0;
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
      overflow: hidden;
      color: var(--text-muted);
      font: inherit;
    }

    .shot img {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .caption {
      position: absolute;
      inset: auto 0 0;
      padding: var(--space-3) var(--space-2) var(--space-1);
      background: linear-gradient(transparent, rgb(8 12 9 / 0.72));
      color: var(--color-neutral-0);
      font-size: var(--font-size-xs);
      text-align: left;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .add {
      gap: var(--space-1);
      border: 1.5px dashed var(--border-strong);
      background: transparent;
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-medium);
    }

    .pending .bar {
      width: 70%;
      height: 4px;
      border-radius: var(--radius-full);
      background: var(--border);
      overflow: hidden;
    }

    .pending .bar span {
      display: block;
      height: 100%;
      background: var(--primary);
      transition: width 120ms linear;
    }

    .file {
      position: absolute;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }
  `,
})
export class EventPictures implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  private readonly files = viewChild.required<ElementRef<HTMLInputElement>>('files');

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly images = signal<EventImages | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly posterBusy = signal(false);
  protected readonly posterProgress = signal<number | null>(null);
  protected readonly uploads = signal<Upload[]>([]);

  protected readonly addIcon = ImagePlus;

  private uploadCount = 0;

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const [event, images] = await Promise.all([this.context.get(this.id()), this.organizer.images(this.id())]);
      this.event.set(event);
      this.images.set(images);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected pick(): void {
    this.files().nativeElement.click();
  }

  private tooBig(file: File): boolean {
    if (file.size <= LIMIT) return false;

    this.toasts.show(`${file.name} is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is 10 MB.`, 'danger');
    return true;
  }

  protected async uploadPoster(file: File): Promise<void> {
    if (this.tooBig(file)) return;

    this.posterBusy.set(true);
    this.posterProgress.set(0);

    try {
      await this.organizer.uploadImage(this.id(), file, 'banner', (p) => this.posterProgress.set(p));
      this.context.forget(this.id());
      await this.load();
      this.toasts.show('Poster up.', 'success');
    } catch (error) {
      this.toasts.show(messageOf(error, 'That picture could not be uploaded.'), 'danger');
    } finally {
      this.posterBusy.set(false);
      this.posterProgress.set(null);
    }
  }

  protected async removePoster(image: EventImage): Promise<void> {
    const sure = await this.dialogs.confirm({ title: 'Remove the poster?', message: 'The event page shows its plain header until another is added.', confirm: 'Remove', danger: true });
    if (!sure) return;

    try {
      await this.organizer.deleteImage(this.id(), image.id);
      this.context.forget(this.id());
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }

  protected async picked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const chosen = Array.from(input.files ?? []);
    input.value = '';

    // One after another: a weak signal then loses one picture, not the lot.
    for (const file of chosen) {
      if (this.tooBig(file)) continue;

      const upload: Upload = { id: ++this.uploadCount, name: file.name, percent: 0 };
      this.uploads.update((all) => [...all, upload]);

      try {
        const image = await this.organizer.uploadImage(this.id(), file, 'gallery', (percent) =>
          this.uploads.update((all) => all.map((u) => (u.id === upload.id ? { ...u, percent } : u))),
        );
        this.images.update((all) => (all ? { ...all, gallery: [...all.gallery, image] } : all));
      } catch (error) {
        this.toasts.show(messageOf(error, `${file.name} could not be uploaded.`), 'danger');
      } finally {
        this.uploads.update((all) => all.filter((u) => u.id !== upload.id));
      }
    }
  }

  protected async imageMenu(image: EventImage, first: boolean, last: boolean): Promise<void> {
    const chosen = await this.dialogs.menu({
      title: image.caption ?? 'Picture',
      actions: [
        { key: 'caption', label: image.caption ? 'Change the caption' : 'Add a caption', icon: MessageSquareText },
        { key: 'poster', label: 'Make it the poster', icon: RectangleHorizontal },
        { key: 'earlier', label: 'Move earlier', icon: ArrowLeft, disabled: first },
        { key: 'later', label: 'Move later', icon: ArrowRight, disabled: last },
        { key: 'delete', label: 'Delete', icon: Trash2, danger: true },
      ],
    });

    try {
      switch (chosen) {
        case 'caption': {
          const caption = await this.dialogs.prompt({
            title: 'Caption',
            label: 'What is in it',
            value: image.caption ?? '',
            placeholder: 'The main room at 1am',
            confirm: 'Save',
          });
          if (caption === null) return;
          const saved = await this.organizer.captionImage(this.id(), image.id, caption.trim() || null);
          this.replace(saved);
          return;
        }
        case 'poster':
          await this.organizer.setBanner(this.id(), image.id);
          this.context.forget(this.id());
          this.toasts.show('That is the poster now.', 'success');
          await this.load();
          return;
        case 'earlier':
        case 'later': {
          const ids = (this.images()?.gallery ?? []).map((i) => i.id);
          const at = ids.indexOf(image.id);
          const to = at + (chosen === 'earlier' ? -1 : 1);
          [ids[at], ids[to]] = [ids[to], ids[at]];
          const { gallery } = await this.organizer.reorderImages(this.id(), ids);
          this.images.update((all) => (all ? { ...all, gallery } : all));
          return;
        }
        case 'delete':
          if (!(await this.dialogs.confirm({ title: 'Delete this picture?', confirm: 'Delete', danger: true }))) return;
          await this.organizer.deleteImage(this.id(), image.id);
          this.images.update((all) => (all ? { ...all, gallery: all.gallery.filter((i) => i.id !== image.id) } : all));
          return;
        default:
          return;
      }
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }

  private replace(image: EventImage): void {
    this.images.update((all) => (all ? { ...all, gallery: all.gallery.map((i) => (i.id === image.id ? image : i)) } : all));
  }
}
