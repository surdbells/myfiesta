import { Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { EventSummary } from '../core/api.types';
import { formatMoney } from '../core/money';

/**
 * One event, as a card.
 *
 * The poster does the selling. Everything else on this card is there to answer
 * the three questions somebody has while scanning — when, where, how much —
 * and they are answered in that order because that is the order they decide
 * in. A card that leads with the price is a card for somebody already sold.
 *
 * The whole card is one link. Not the title alone: on a phone the title is a
 * short line in a tall card and the rest of it looks tappable because it *is*
 * a card, so making only the text work reads as broken rather than as design.
 */
@Component({
  selector: 'app-event-card',
  imports: [RouterLink],
  template: `
    <article
      class="evt group relative h-full min-w-0"
      [attr.data-sold]="event().is_sold_out ? '' : null"
      [attr.data-wide]="wide() ? '' : null"
    >
      <a class="evt__link grid h-full grid-rows-[auto_1fr] gap-3 rounded-lg text-inherit no-underline group-data-[wide]:grid-rows-none group-data-[wide]:grid-cols-[12rem_1fr] group-data-[wide]:items-center group-data-[wide]:gap-6 group-data-[wide]:rounded-lg group-data-[wide]:border group-data-[wide]:border-border group-data-[wide]:bg-surface-raised group-data-[wide]:p-4 group-data-[wide]:transition-colors group-data-[wide]:hover:border-border-strong group-data-[wide]:max-sm:grid-cols-none group-data-[wide]:max-sm:grid-rows-[auto_1fr] group-data-[wide]:max-sm:gap-3 group-data-[wide]:max-sm:border-0 group-data-[wide]:max-sm:bg-transparent group-data-[wide]:max-sm:p-0" [routerLink]="['/', event().slug]">
        <div class="evt__frame relative aspect-[3/4] overflow-hidden rounded-lg bg-surface-inset group-data-[wide]:aspect-[4/3] group-data-[wide]:max-sm:aspect-[3/4]">
          @if (event().poster_url) {
            <img
              class="evt__poster block h-full w-full object-cover transition-transform duration-(--motion-slow) ease-(--motion-ease) group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100 group-data-[sold]:opacity-[0.62] group-data-[sold]:grayscale-[0.7]"
              [src]="event().poster_url"
              alt=""
              [loading]="eager() ? 'eager' : 'lazy'"
              [attr.fetchpriority]="eager() ? 'high' : null"
              decoding="async"
            />
          } @else {
            <!-- Not a grey box. An event with no poster still has a name, and
                 the initial is enough to tell two cards apart in a rail. -->
            <div class="evt__fallback relative grid h-full place-items-center overflow-hidden bg-[radial-gradient(120%_90%_at_20%_0%,color-mix(in_srgb,var(--color-brand-500)_55%,transparent),transparent_60%),linear-gradient(155deg,var(--color-brand-900),var(--color-neutral-950))] after:absolute after:inset-x-0 after:bottom-0 after:h-[3px] after:bg-[linear-gradient(90deg,var(--color-gold-400),transparent_70%)] after:content-['']" aria-hidden="true">
              <span class="text-[3.5rem] font-bold tracking-[-0.02em] text-[rgba(255,255,255,0.92)] group-data-[wide]:text-[3rem]">{{ initial() }}</span>
            </div>
          }

          @if (event().is_sold_out) {
            <span class="evt__flag absolute left-3 top-3 rounded-full bg-[rgba(8,12,9,0.78)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.08em] text-text-inverse backdrop-blur-[4px]">Sold out</span>
          } @else if (soon()) {
            <span class="evt__flag absolute left-3 top-3 rounded-full bg-primary px-3 py-1 text-xs font-semibold uppercase tracking-[0.08em] text-on-primary">This week</span>
          }
        </div>

        <div class="evt__body grid min-w-0 content-start gap-1 group-data-[wide]:gap-2">
          <p class="evt__when text-xs font-semibold uppercase tracking-[0.08em] text-primary-text">{{ when() }}</p>
          <h3 class="evt__title line-clamp-2 text-base font-semibold leading-[1.35] group-data-[wide]:text-xl group-data-[wide]:tracking-[-0.02em] group-data-[wide]:max-sm:text-base">{{ event().title }}</h3>
          <p class="evt__where text-sm text-text-muted group-data-[wide]:text-base">{{ event().city }}</p>
          <p class="evt__price text-sm font-medium text-text tabular-nums group-data-[sold]:font-normal group-data-[sold]:text-text-subtle group-data-[wide]:mt-1 group-data-[wide]:text-base">{{ price() }}</p>
        </div>
      </a>
    </article>
  `,
})
export class EventCard {
  readonly event = input.required<EventSummary>();

  /** Above the fold. Loads eagerly and at high priority. */
  readonly eager = input(false);

  /**
   * The horizontal layout: poster left, everything else beside it.
   *
   * Used where a section has only one or two events. A row sized for six
   * cards holding one is not a sparse row, it is a broken one — and at
   * launch that is most of the front page. Wide, the same event fills the
   * space it was given and reads as a feature rather than as a remainder.
   */
  readonly wide = input(false);

  readonly initial = computed(() => this.event().title.trim().charAt(0).toUpperCase() || '?');

  readonly when = computed(() =>
    new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: 'numeric',
      minute: '2-digit',
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

  /**
   * Within the next seven days.
   *
   * Worth flagging because urgency is the whole reason somebody buys tonight
   * rather than bookmarking. Only ever shown when it is true.
   */
  readonly soon = computed(() => {
    const starts = new Date(this.event().starts_at).getTime();
    const week = 7 * 24 * 60 * 60 * 1000;

    return starts > Date.now() && starts - Date.now() < week;
  });
}
