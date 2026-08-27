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
    <article class="evt" [class.evt--sold]="event().is_sold_out" [class.evt--wide]="wide()">
      <a class="evt__link" [routerLink]="['/', event().slug]">
        <div class="evt__frame">
          @if (event().poster_url) {
            <img
              class="evt__poster"
              [src]="event().poster_url"
              alt=""
              [loading]="eager() ? 'eager' : 'lazy'"
              [attr.fetchpriority]="eager() ? 'high' : null"
              decoding="async"
            />
          } @else {
            <!-- Not a grey box. An event with no poster still has a name, and
                 the initial is enough to tell two cards apart in a rail. -->
            <div class="evt__fallback" aria-hidden="true">
              <span>{{ initial() }}</span>
            </div>
          }

          @if (event().is_sold_out) {
            <span class="evt__flag">Sold out</span>
          } @else if (soon()) {
            <span class="evt__flag evt__flag--soon">This week</span>
          }
        </div>

        <div class="evt__body">
          <p class="evt__when">{{ when() }}</p>
          <h3 class="evt__title">{{ event().title }}</h3>
          <p class="evt__where">{{ event().city }}</p>
          <p class="evt__price">{{ price() }}</p>
        </div>
      </a>
    </article>
  `,
  styleUrl: './event-card.css',
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
