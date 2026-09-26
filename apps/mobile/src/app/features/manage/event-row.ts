import { Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MfBadge } from '../../ui';
import { formatMoney, type Money } from '../../core/money';
import { dayOf, until } from '../../core/when';

/** What a row needs; both the events list and the hub's "selling now" have it. */
export interface EventRowData {
  id: string;
  title: string;
  starts_at: string;
  timezone: string;
  city: string;
  status?: string;
  tickets_issued: number;
  capacity: number | null;
  poster_url: string | null;
  revenue?: Money | null;
  net?: Money | null;
  last_sale_at?: string | null;
}

/**
 * One event, as a card to judge it by without opening it.
 *
 * The poster — the way an organizer recognises their own nights — then the
 * date and room, how long until, how much of the room is gone, and what it
 * has earned. A draft or a cancelled night says so before anything else.
 */
@Component({
  selector: 'mf-event-row',
  imports: [RouterLink, MfBadge],
  template: `
    <a class="row" [routerLink]="['/manage/events', event().id]">
      @if (event().poster_url) {
        <img class="poster" [src]="event().poster_url" alt="" loading="lazy" />
      } @else {
        <span class="poster blank" aria-hidden="true">{{ initial() }}</span>
      }

      <span class="body">
        <span class="top">
          <span class="title">{{ event().title }}</span>
          @if (event().status === 'draft') {
            <mf-badge tone="warning">Draft</mf-badge>
          } @else if (event().status === 'cancelled') {
            <mf-badge tone="danger">Cancelled</mf-badge>
          }
        </span>

        <span class="where">{{ day() }} · {{ event().city }}</span>

        <span class="numbers">
          <span class="sold">
            <strong>{{ event().tickets_issued.toLocaleString() }}</strong>
            @if (event().capacity) {
              <span class="of">/ {{ event().capacity!.toLocaleString() }}</span>
            }
            sold
          </span>
          @if (earned(); as money) {
            <span class="earned">{{ money }}</span>
          }
          <span class="when">{{ when() }}</span>
        </span>

        @if (portion() !== null) {
          <span class="meter" aria-hidden="true">
            <span [style.width.%]="portion()" [class.full]="portion()! >= 100"></span>
          </span>
        }
      </span>
    </a>
  `,
  styles: `
    :host {
      display: block;
    }

    .row {
      display: flex;
      gap: var(--space-4);
      padding: var(--space-3);
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-raised);
      color: var(--text);
      text-decoration: none;
      transition:
        transform 120ms ease,
        box-shadow 160ms ease;
    }

    .row:active {
      transform: scale(0.985);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-card);
    }

    .poster {
      flex: none;
      width: 64px;
      height: 80px;
      border-radius: var(--radius-lg);
      object-fit: cover;
      background: var(--surface-inset);
    }

    .poster.blank {
      display: grid;
      place-items: center;
      background:
        radial-gradient(120% 90% at 20% 0%, color-mix(in srgb, var(--color-brand-500) 50%, transparent), transparent 60%),
        linear-gradient(155deg, var(--color-brand-900), var(--color-neutral-950));
      color: rgb(255 255 255 / 0.9);
      font-family: var(--font-family-display);
      font-size: var(--font-size-2xl);
      font-weight: var(--font-weight-semibold);
    }

    .body {
      flex: 1;
      display: grid;
      align-content: center;
      gap: 3px;
      min-width: 0;
    }

    .top {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      min-width: 0;
    }

    .title {
      flex: 1;
      min-width: 0;
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .where {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .numbers {
      display: flex;
      align-items: baseline;
      gap: var(--space-3);
      margin-top: 2px;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }

    .sold strong {
      font-family: var(--font-family-display);
      font-weight: var(--font-weight-semibold);
      color: var(--text);
    }

    .earned {
      font-family: var(--font-family-display);
      color: var(--primary-text);
      font-weight: var(--font-weight-semibold);
    }

    .when {
      margin-left: auto;
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      color: var(--text-subtle);
    }

    .meter {
      display: block;
      height: 4px;
      margin-top: var(--space-1);
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      overflow: hidden;
    }

    .meter span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: var(--primary);
    }

    .meter span.full {
      background: var(--accent);
    }
  `,
})
export class MfEventRow {
  readonly event = input.required<EventRowData>();

  protected readonly initial = computed(() => this.event().title.trim().charAt(0).toUpperCase() || '?');
  protected readonly day = computed(() => dayOf(this.event().starts_at, this.event().timezone));
  protected readonly when = computed(() => until(this.event().starts_at));

  protected readonly earned = computed(() => {
    const money = this.event().revenue ?? this.event().net ?? null;

    return money && money.amount > 0 ? formatMoney(money) : null;
  });

  protected readonly portion = computed(() => {
    const { capacity, tickets_issued } = this.event();

    return capacity ? Math.min(100, Math.round((tickets_issued / capacity) * 100)) : null;
  });
}
