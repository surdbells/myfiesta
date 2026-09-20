import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import { CalendarDays, Heart, MapPin } from 'lucide-angular';
import { EventSummary } from '../core/api.types';
import { Saves } from '../core/saves';
import { formatMoney } from '../core/money';

/**
 * One event, as a card — and, wide, as a listing row.
 *
 * The poster does the selling; the chips on it answer category and date
 * without leaving the picture. The whole card is one link, and the heart is
 * the one control inside it that is not — it bookmarks without navigating,
 * which is why it swallows the click.
 */
@Component({
  selector: 'app-event-card',
  imports: [RouterLink, UiIcon],
  template: `
    @if (wide()) {
      <!-- The listing row: poster left, the facts in the middle, the price
           and the button holding the end. -->
      <article
        class="evt group relative h-full min-w-0 rounded-xl border border-border bg-surface-raised p-4 shadow-(--shadow-card) transition-[transform,box-shadow,border-color] duration-(--motion-base) ease-(--motion-ease) hover:-translate-y-0.5 hover:border-primary hover:shadow-(--shadow-raised) motion-reduce:transition-none motion-reduce:hover:translate-y-0"
      >
        <a
          class="evt__link grid grid-cols-[14rem_minmax(0,1fr)_auto] items-center gap-6 text-inherit no-underline max-sm:grid-cols-1"
          [routerLink]="['/', event().slug]"
        >
          <span class="relative block overflow-hidden rounded-lg">
            @if (event().poster_url) {
              <img
                class="aspect-video w-full bg-surface-inset object-cover transition-transform duration-(--motion-slow) ease-(--motion-ease) group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                [src]="event().poster_url"
                alt=""
                [loading]="eager() ? 'eager' : 'lazy'"
                decoding="async"
              />
            } @else {
              <!-- The same lit panel the tall card uses, so one event does
                   not get two different stand-ins depending on the rail. -->
              <span
                class="grid aspect-video w-full place-items-center bg-[radial-gradient(120%_90%_at_20%_0%,color-mix(in_srgb,var(--color-brand-500)_55%,transparent),transparent_60%),linear-gradient(155deg,var(--color-brand-900),var(--color-neutral-950))]"
                aria-hidden="true"
              >
                <span class="figure text-4xl text-[rgba(255,255,255,0.92)]">{{ initial() }}</span>
              </span>
            }
          </span>

          <span class="grid min-w-0 content-center gap-2">
            <span class="flex flex-wrap items-center gap-3 text-xs">
              @if (event().category) {
                <span class="rounded-full bg-primary-soft px-3 py-1 font-semibold text-primary-soft-text">{{ event().category }}</span>
              }
              <span class="font-semibold uppercase tracking-[0.08em] text-primary-text">{{ shortWhen() }}</span>
            </span>
            <span class="evt__title line-clamp-2 text-xl font-semibold tracking-[-0.02em]">{{ event().title }}</span>
            <span class="flex items-center gap-2 text-sm text-text-muted">
              <ui-icon class="shrink-0 text-text-subtle" [icon]="whereIcon" size="sm" />
              {{ event().city }}
            </span>
          </span>

          <span class="grid justify-items-end gap-3 max-sm:justify-items-start">
            <span class="evt__price figure text-lg text-text" [class.text-text-subtle]="event().is_sold_out">{{ price() }}</span>
            @if (!event().is_sold_out) {
              <!-- An affordance, not a second destination: the whole card is
                   the link, and this is where the eye expects the action. -->
              <span class="rounded-full bg-primary px-5 py-2 text-sm font-semibold text-on-primary">Book now</span>
            }
          </span>
        </a>
      </article>
    } @else {
      <article class="evt group relative h-full min-w-0" [attr.data-sold]="event().is_sold_out ? '' : null">
        <a
          class="evt__link grid h-full grid-rows-[auto_1fr] rounded-xl border border-border bg-surface-raised text-inherit no-underline shadow-(--shadow-card) transition-[transform,box-shadow,border-color] duration-(--motion-base) ease-(--motion-ease) hover:-translate-y-0.5 hover:border-primary hover:shadow-(--shadow-raised) overflow-hidden"
          [routerLink]="['/', event().slug]"
        >
          <span class="evt__frame relative block overflow-hidden bg-surface-inset">
            @if (event().poster_url) {
              <img
                class="evt__poster block aspect-[4/3] w-full object-cover transition-transform duration-(--motion-slow) ease-(--motion-ease) group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100 group-data-[sold]:opacity-[0.62] group-data-[sold]:grayscale-[0.7]"
                [src]="event().poster_url"
                alt=""
                [loading]="eager() ? 'eager' : 'lazy'"
                [attr.fetchpriority]="eager() ? 'high' : null"
                decoding="async"
              />
            } @else {
              <span
                class="evt__fallback relative grid aspect-[4/3] w-full place-items-center overflow-hidden bg-[radial-gradient(120%_90%_at_20%_0%,color-mix(in_srgb,var(--color-brand-500)_55%,transparent),transparent_60%),linear-gradient(155deg,var(--color-brand-900),var(--color-neutral-950))] after:absolute after:inset-x-0 after:bottom-0 after:h-[3px] after:bg-[linear-gradient(90deg,var(--color-gold-400),transparent_70%)] after:content-['']"
                aria-hidden="true"
              >
                <span class="figure text-[3rem] text-[rgba(255,255,255,0.92)]">{{ initial() }}</span>
              </span>
            }

            @if (event().category) {
              <span class="absolute left-3 top-3 rounded-full bg-surface-raised px-3 py-1 text-xs font-semibold text-primary-text shadow-(--shadow-card)">{{ event().category }}</span>
            }

            <span class="absolute bottom-3 left-3 rounded-full bg-[rgba(8,12,9,0.78)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.04em] text-neutral-0 backdrop-blur-[4px]">
              @if (event().is_sold_out && !past()) {
                Sold out
              } @else {
                {{ shortWhen() }}
              }
            </span>
          </span>

          <span class="evt__body grid content-start gap-2 p-4">
            <span class="evt__title line-clamp-2 text-base font-semibold leading-[1.35]">{{ event().title }}</span>
            <span class="evt__where flex items-center gap-2 text-sm text-text-muted">
              <ui-icon class="shrink-0 text-text-subtle" [icon]="whereIcon" size="sm" />
              {{ event().city }}
            </span>
            <span class="mt-1 flex items-center justify-between gap-3 border-t border-border pt-3">
              @if (past()) {
                <!-- No price and no call to action: this night is over, and
                     the page it links to says so. -->
                <span class="text-sm text-text-subtle">Past event</span>
              } @else {
                <span class="evt__price text-sm font-semibold text-primary-text tabular-nums group-data-[sold]:font-normal group-data-[sold]:text-text-subtle">{{ price() }}</span>
                @if (!event().is_sold_out) {
                  <span class="rounded-full bg-primary px-4 py-1.5 text-xs font-semibold text-on-primary">Details</span>
                }
              }
            </span>
          </span>
        </a>

        <!-- Inside the card, outside the link: a bookmark, not a navigation. -->
        <button
          class="absolute right-3 top-3 grid h-9 w-9 cursor-pointer place-items-center rounded-full border-0 bg-[rgba(8,12,9,0.55)] backdrop-blur-[4px] transition-colors duration-(--motion-fast) ease-(--motion-ease) hover:bg-[rgba(8,12,9,0.8)]"
          [class]="saves.has(event().slug) ? 'text-gold-400' : 'text-neutral-0'"
          type="button"
          [attr.aria-pressed]="saves.has(event().slug)"
          [attr.aria-label]="saves.has(event().slug) ? 'Saved — tap to remove' : 'Save this event'"
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
   * Shown under "Previously" on an organizer page, where the card is proof
   * rather than an offer. Without this the same card reads as something to
   * buy: a date with no year that could be next month, a price, and a button
   * that says Details — for a party that was last August.
   */
  readonly past = input(false);

  readonly saves = inject(Saves);

  protected readonly whereIcon = MapPin;
  protected readonly whenIcon = CalendarDays;
  protected readonly saveIcon = Heart;

  readonly initial = computed(() => this.event().title.trim().charAt(0).toUpperCase() || '?');

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
    if (!event.from_price) return 'Free';

    return `From ${formatMoney(event.from_price)}`;
  });

  toggleSave(click: Event): void {
    click.preventDefault();
    click.stopPropagation();
    this.saves.toggle(this.event().slug);
  }
}
