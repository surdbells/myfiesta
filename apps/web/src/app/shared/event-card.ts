import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import { ArrowRight, CalendarDays, Heart, MapPin } from 'lucide-angular';
import { EventSummary } from '../core/api.types';
import { Saves } from '../core/saves';
import { formatMoney } from '../core/money';
import { offSale } from '@myfiesta/shared/availability';
import { AvailabilityBadge } from './availability-badge';
import { PosterArt } from './poster-art';

/**
 * One event, as a card — and, wide, as a listing row.
 *
 * The poster does the selling; what is on it answers the two questions a
 * glance asks — is it going fast, and what kind of night is it — without
 * leaving the picture. The body answers when, where and what it costs. The
 * whole card is one link, and the heart is the one control inside it that is
 * not: it bookmarks without navigating, which is why it swallows the click.
 *
 * The badge is the API's (Availability): "Almost sold out", "Sold out" or
 * "Sales closed", counted the way checkout counts. Never an exact number here — that belongs
 * beside the ticket itself.
 */
@Component({
  selector: 'app-event-card',
  imports: [RouterLink, UiIcon, AvailabilityBadge, PosterArt],
  template: `
    @if (wide()) {
      <!-- The listing row: poster left, the facts in the middle, the price
           and the button holding the end. -->
      <article
        class="evt group relative h-full min-w-0 rounded-xl border border-border bg-surface-raised p-4 shadow-(--shadow-card) transition-[transform,box-shadow,border-color] duration-(--motion-base) ease-(--motion-ease) hover:-translate-y-0.5 hover:border-primary hover:shadow-(--shadow-raised) motion-reduce:transition-none motion-reduce:hover:translate-y-0"
        [attr.data-sold]="soldOut() ? '' : null"
      >
        <a
          class="evt__link grid grid-cols-[14rem_minmax(0,1fr)_auto] items-center gap-6 rounded-lg text-inherit no-underline max-sm:grid-cols-1"
          [routerLink]="['/', event().slug]"
        >
          <span class="relative block aspect-video overflow-hidden rounded-lg bg-surface-inset">
            @if (event().poster_url) {
              <img
                class="h-full w-full object-cover transition-transform duration-(--motion-slow) ease-(--motion-ease) group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                [src]="event().poster_url"
                alt=""
                width="640"
                height="360"
                [attr.loading]="eager() ? 'eager' : 'lazy'"
                [attr.fetchpriority]="eager() ? 'high' : null"
                decoding="async"
              />
            } @else {
              <app-poster-art [title]="event().title" [category]="event().category" [glyph]="120" />
            }
            @if (!past()) {
              <span class="absolute left-2.5 top-2.5"><app-availability [value]="event().availability" /></span>
            }
          </span>

          <span class="grid min-w-0 content-center gap-2">
            <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
              <span class="font-semibold uppercase tracking-[0.08em] text-primary-text">{{ shortWhen() }}</span>
              @if (event().category) {
                <span class="text-text-subtle">{{ event().category }}</span>
              }
            </span>
            <span class="evt__title line-clamp-2 text-xl font-semibold tracking-[-0.02em]">{{ event().title }}</span>
            <span class="flex items-center gap-2 text-sm text-text-muted">
              <ui-icon class="shrink-0 text-text-subtle" [icon]="whereIcon" size="sm" />
              {{ event().city }}
            </span>
          </span>

          <span class="grid justify-items-end gap-3 max-sm:justify-items-start">
            <span class="evt__price figure text-lg" [class]="soldOut() || past() ? 'text-text-subtle' : 'text-text'">{{ price() }}</span>
            @if (!past()) {
              <!-- An affordance, not a second destination: the whole card is
                   the link, and this is where the eye expects the action. -->
              <span
                class="rounded-full px-5 py-2 text-sm font-semibold"
                [class]="soldOut() ? 'border border-field-border text-text' : 'bg-primary text-on-primary'"
                >{{ action() }}</span
              >
            }
          </span>
        </a>
      </article>
    } @else {
      <article class="evt group relative h-full min-w-0" [attr.data-sold]="soldOut() && !past() ? '' : null">
        <a
          class="evt__link grid h-full grid-rows-[auto_1fr] overflow-hidden rounded-xl border border-border bg-surface-raised text-inherit no-underline shadow-(--shadow-card) transition-[transform,box-shadow,border-color] duration-(--motion-base) ease-(--motion-ease) hover:-translate-y-0.5 hover:border-primary hover:shadow-(--shadow-raised) motion-reduce:transition-none motion-reduce:hover:translate-y-0"
          [routerLink]="['/', event().slug]"
        >
          <span class="evt__frame relative block aspect-[4/3] overflow-hidden bg-surface-inset">
            @if (event().poster_url) {
              <img
                class="evt__poster block h-full w-full object-cover transition-transform duration-(--motion-slow) ease-(--motion-ease) group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100 group-data-[sold]:saturate-[0.35]"
                [src]="event().poster_url"
                alt=""
                width="480"
                height="360"
                [attr.loading]="eager() ? 'eager' : 'lazy'"
                [attr.fetchpriority]="eager() ? 'high' : null"
                decoding="async"
              />
            } @else {
              <app-poster-art [title]="event().title" [category]="event().category" />
            }

            <!-- Legible on any poster: a scrim under the chips rather than a
                 guess about what colour the artwork is. -->
            <span
              class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2 bg-[linear-gradient(to_top,color-mix(in_srgb,var(--color-neutral-950)_62%,transparent),transparent)]"
              aria-hidden="true"
            ></span>

            <!-- Along the foot of the poster, clear of the heart in the top
                 corner: on a phone's two-column grid a badge up there ran
                 under it. The badge first; the category wraps below it. -->
            <span class="absolute inset-x-3 bottom-3 flex flex-wrap items-center gap-1.5">
              @if (!past()) {
                <app-availability [value]="event().availability" />
              }
              @if (event().category) {
                <span
                  class="max-w-full truncate rounded-full bg-[color-mix(in_srgb,var(--color-neutral-950)_55%,transparent)] px-2.5 py-1 text-xs font-semibold text-neutral-0 backdrop-blur-[6px]"
                  >{{ event().category }}</span
                >
              }
            </span>
          </span>

          <span class="evt__body grid content-start gap-1.5 p-4 max-sm:p-3">
            <span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.06em] text-primary-text">
              <ui-icon class="shrink-0" [icon]="whenIcon" size="sm" />
              <span class="truncate">{{ shortWhen() }}</span>
            </span>
            <span class="evt__title line-clamp-2 text-base font-semibold leading-[1.3] max-sm:text-[15px]">{{ event().title }}</span>
            <span class="evt__where flex min-w-0 items-center gap-1.5 text-sm text-text-muted">
              <ui-icon class="shrink-0 text-text-subtle" [icon]="whereIcon" size="sm" />
              <span class="truncate">{{ event().city }}</span>
            </span>
            <span class="mt-2 flex items-center justify-between gap-3 border-t border-border pt-3">
              @if (past()) {
                <!-- No price and no call to action: this night is over, and
                     the page it links to says so. -->
                <span class="text-sm text-text-subtle">Past event</span>
              } @else {
                <span
                  class="evt__price min-w-0 truncate text-sm font-semibold tabular-nums"
                  [class]="soldOut() ? 'text-text-subtle' : 'text-text'"
                  >{{ price() }}</span
                >
                <span
                  class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-primary-text transition-transform duration-(--motion-fast) ease-(--motion-ease) group-hover:translate-x-0.5 motion-reduce:transition-none max-sm:hidden"
                  >{{ action() }} <ui-icon [icon]="arrowIcon" size="sm" class="scale-75"
                /></span>
              }
            </span>
          </span>
        </a>

        <!-- Inside the card, outside the link: a bookmark, not a navigation. -->
        <button
          class="absolute right-3 top-3 grid h-9 w-9 cursor-pointer place-items-center rounded-full border-0 bg-[color-mix(in_srgb,var(--color-neutral-950)_55%,transparent)] backdrop-blur-[4px] transition-colors duration-(--motion-fast) ease-(--motion-ease) hover:bg-[color-mix(in_srgb,var(--color-neutral-950)_80%,transparent)]"
          [class]="saves.has(event().slug) ? 'text-gold-400' : 'text-neutral-0'"
          type="button"
          [attr.aria-pressed]="saves.has(event().slug)"
          [attr.aria-label]="saves.has(event().slug) ? 'Saved — tap to remove' : 'Save ' + event().title"
          (click)="toggleSave($event)"
        >
          <ui-icon [icon]="saveIcon" size="sm" [class]="saves.has(event().slug) ? 'fill-current' : ''" />
        </button>
      </article>
    }
  `,
})
export class EventCard {
  readonly event = input.required<EventSummary>();

  /** Above the fold. Loads eagerly and at high priority. */
  readonly eager = input(false);

  /** The listing-row layout, for sections holding one or two events. */
  readonly wide = input(false);

  /**
   * A night that has already happened.
   *
   * Shown under "Previously" on an organizer page and on the front page's
   * "Recently" shelf, where the card is proof rather than an offer. Without
   * this the same card reads as something to buy: a date with no year that
   * could be next month, a price, and a button — for a party that was last
   * August.
   */
  readonly past = input(false);

  readonly saves = inject(Saves);

  protected readonly whereIcon = MapPin;
  protected readonly whenIcon = CalendarDays;
  protected readonly saveIcon = Heart;
  protected readonly arrowIcon = ArrowRight;

  /** Nothing to buy on it — sold out, or sales closed — so the card goes quiet. */
  readonly soldOut = computed(() => this.event().is_sold_out || offSale(this.event().availability));

  readonly shortWhen = computed(() =>
    new Intl.DateTimeFormat('en-CA', {
      // A night that has gone gets its year and loses its clock: the time
      // somebody should have arrived is no use to anybody now, and a date
      // with no year reads as one coming up.
      ...(this.past()
        ? { day: 'numeric' as const, month: 'short' as const, year: 'numeric' as const }
        : {
            weekday: 'short' as const,
            day: 'numeric' as const,
            month: 'short' as const,
            hour: 'numeric' as const,
            minute: '2-digit' as const,
          }),
      // The venue's zone, never the reader's. A Lagos event says 10pm to
      // somebody reading in Toronto.
      timeZone: this.event().timezone,
    }).format(new Date(this.event().starts_at)),
  );

  readonly price = computed(() => {
    const event = this.event();

    if (event.is_sold_out) return 'Sold out';
    if (event.availability?.state === 'closed') return 'Sales closed';
    if (!event.from_price) return 'Tickets soon';
    if (event.from_price.amount === 0) return 'Free';

    return `From ${formatMoney(event.from_price)}`;
  });

  /**
   * What the card leads to: tickets, a place in the queue for returns, or —
   * when sales closed with places left — only the page, since there is
   * nothing to buy and nothing coming back.
   */
  readonly action = computed(() => {
    const event = this.event();

    if (!this.soldOut()) return 'Get tickets';

    return event.waitlist ? 'Join waitlist' : 'View';
  });

  toggleSave(click: Event): void {
    click.preventDefault();
    click.stopPropagation();
    this.saves.toggle(this.event().slug);
  }
}
