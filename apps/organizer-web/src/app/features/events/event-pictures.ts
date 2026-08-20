import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { EventImage } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * The banner, and the gallery.
 *
 * The shared link is the sales channel, and a link with no picture unfurls as a
 * line of text. This screen is where that gets fixed, so the banner leads and
 * the gallery — which only matters after the night — sits below it.
 *
 * Ordering is done with buttons rather than dragging. A drag needs a pointer,
 * and half the people who will use this are on a phone the morning after an
 * event; two buttons work everywhere, including from a keyboard.
 */
@Component({
  selector: 'app-event-pictures',
  imports: [FormsModule, RouterLink],
  templateUrl: './event-pictures.html',
  styleUrl: './event-pictures.css',
})
export class EventPictures {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = this.route.snapshot.paramMap.get('id')!;

  readonly banner = signal<EventImage | null>(null);
  readonly gallery = signal<EventImage[]>([]);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  /** How many of a multi-select are still going up. */
  readonly remaining = signal(0);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.images(this.eventId).subscribe({
      next: ({ banner, gallery }) => {
        this.banner.set(banner);
        this.gallery.set(gallery);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load this event’s pictures.'));
      },
    });
  }

  pickBanner(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) this.upload([file], 'banner');

    // Cleared so choosing the same file twice still fires a change event —
    // otherwise a failed upload cannot be retried without picking something
    // else first.
    (event.target as HTMLInputElement).value = '';
  }

  pickGallery(event: Event): void {
    const files = [...((event.target as HTMLInputElement).files ?? [])];

    if (files.length > 0) this.upload(files, 'gallery');

    (event.target as HTMLInputElement).value = '';
  }

  /**
   * One at a time, on purpose.
   *
   * Twenty photos fired at once from a phone on venue wifi is twenty requests
   * competing for the same connection, and the failure mode is all of them
   * timing out rather than the first fifteen succeeding. Sequential is slower
   * and finishes.
   */
  private upload(files: File[], kind: 'banner' | 'gallery'): void {
    this.busy.set(true);
    this.error.set(null);
    this.notice.set(null);
    this.remaining.set(files.length);

    const next = (index: number): void => {
      if (index >= files.length) {
        this.busy.set(false);
        this.remaining.set(0);
        this.notice.set(
          kind === 'banner'
            ? 'Banner updated.'
            : `${files.length} ${files.length === 1 ? 'picture' : 'pictures'} added.`,
        );
        this.load();

        return;
      }

      this.api.uploadImage(this.eventId, files[index], kind).subscribe({
        next: () => {
          this.remaining.set(files.length - index - 1);
          next(index + 1);
        },
        error: (response) => {
          this.busy.set(false);
          this.remaining.set(0);
          // Named, because "one of these failed" is not actionable when
          // twenty were selected.
          this.error.set(
            `${files[index].name}: ${messageFor(response, 'That picture could not be uploaded.')}`,
          );
          this.load();
        },
      });
    };

    next(0);
  }

  caption(image: EventImage, caption: string): void {
    const trimmed = caption.trim();

    if (trimmed === (image.caption ?? '')) return;

    this.api.captionImage(this.eventId, image.id, trimmed || null).subscribe({
      next: (updated) => {
        this.gallery.set(this.gallery().map((i) => (i.id === updated.id ? updated : i)));
      },
      error: (response) => this.error.set(messageFor(response, 'That caption was not saved.')),
    });
  }

  remove(image: EventImage): void {
    this.error.set(null);

    this.api.deleteImage(this.eventId, image.id).subscribe({
      next: () => {
        this.notice.set('Removed.');
        this.load();
      },
      error: (response) => this.error.set(messageFor(response, 'That picture was not removed.')),
    });
  }

  move(image: EventImage, by: -1 | 1): void {
    const order = this.gallery().map((i) => i.id);
    const from = order.indexOf(image.id);
    const to = from + by;

    if (to < 0 || to >= order.length) return;

    [order[from], order[to]] = [order[to], order[from]];

    // Moved locally first so the picture goes where it was pushed rather than
    // a moment later. The server's answer replaces it either way.
    const optimistic = [...this.gallery()];
    [optimistic[from], optimistic[to]] = [optimistic[to], optimistic[from]];
    this.gallery.set(optimistic);

    this.api.reorderImages(this.eventId, order).subscribe({
      next: ({ gallery }) => this.gallery.set(gallery),
      error: (response) => {
        this.error.set(messageFor(response, 'That order was not saved.'));
        this.load();
      },
    });
  }

  isFirst(image: EventImage): boolean {
    return this.gallery()[0]?.id === image.id;
  }

  isLast(image: EventImage): boolean {
    return this.gallery()[this.gallery().length - 1]?.id === image.id;
  }
}
