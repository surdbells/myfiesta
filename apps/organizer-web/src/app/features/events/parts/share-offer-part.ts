import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, UiButton } from '@myfiesta/ui';
import { switchMap, tap } from 'rxjs';
import { Api } from '../../../core/api';
import { OrganizerEventDetail, OrganizerShareOffer } from '../../../core/api.types';
import { messageFor } from '../../../core/errors';
import { SessionStore } from '../../../core/session';
import { ShareApi } from '../../share/share-api';

/**
 * "Friend buys, both save": the event's share offer, on its Overview.
 *
 * The SHARE track's own file. The Overview places it once and never edits it
 * again. Each buyer is given a link; a friend who buys through it saves the
 * offer's percentage, and once the friend has paid the buyer is emailed a
 * single-use code worth the same off the organizer's next events, up to
 * `max_rewards` friends per link. The organizer pays for both, so starting or
 * changing it asks them to say so first.
 *
 * Who may change it is the codes permission (codes.manage), as for any
 * discount; everybody who can open the event sees how it is doing.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras), and the Overview
 * takes it from there.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-share-offer-part',
  host: { class: 'contents' },
  imports: [FormsModule, UiButton],
  template: `
    @if (offer(); as offer) {
      <section class="share-offer card mt-8 p-6" aria-labelledby="share-offer-heading">
        <h2 id="share-offer-heading" class="section mb-1 text-base font-semibold">Friend buys, both save</h2>

        @if (offer.discount_bps !== null) {
          <p class="m-0 text-sm">
            Friends save <strong>{{ percent(offer.discount_bps) }}</strong> through a buyer's link, and the buyer gets
            {{ percent(offer.discount_bps) }} off your next events for each friend who pays, up to
            {{ offer.max_rewards }} per link.
          </p>
          @if (offer.max_bps <= 0) {
            <p class="help mt-2 mb-0 text-xs text-text-muted">
              Friend discounts are switched off on myFiesta at the moment, so links take nothing off.
            </p>
          } @else if (offer.discount_bps > offer.max_bps) {
            <p class="help mt-2 mb-0 text-xs text-text-muted">
              myFiesta's largest friend discount is now {{ percent(offer.max_bps) }}, so friends save
              {{ percent(offer.max_bps) }} until you change the offer.
            </p>
          }
        } @else {
          <p class="m-0 text-sm text-text-muted">
            Give each buyer a link to pass on. A friend who buys through it saves, and so does the buyer, on their
            next tickets from you. You fund both discounts.
          </p>
        }

        @if (offer.links > 0 || offer.discount_bps !== null) {
          <dl class="share-counts mt-4 mb-0 grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3">
            <div>
              <dt class="text-xs text-text-muted">Links handed out</dt>
              <dd class="m-0 text-lg font-semibold tabular-nums">{{ offer.links }}</dd>
            </div>
            <div>
              <dt class="text-xs text-text-muted">Friends who bought</dt>
              <dd class="m-0 text-lg font-semibold tabular-nums">{{ offer.friend_orders }}</dd>
            </div>
            <div>
              <dt class="text-xs text-text-muted">Rewards sent</dt>
              <dd class="m-0 text-lg font-semibold tabular-nums">{{ offer.rewards }}</dd>
            </div>
          </dl>
        }

        @if (!session.canManageCodes() || closed()) {
          <!-- Nothing to change: no permission, or the night is cancelled or over. -->
        } @else if (offer.max_bps <= 0 && offer.discount_bps === null) {
          <p class="help mt-3 mb-0 text-xs text-text-muted">Friend discounts are switched off on myFiesta at the moment.</p>
        } @else if (editing()) {
          <form class="share-form mt-4 flex flex-wrap items-end gap-3" (ngSubmit)="save()">
            <div class="field min-w-0">
              <label for="sharePercent">Friends save (%)</label>
              <input
                id="sharePercent"
                name="sharePercent"
                class="w-28"
                type="number"
                min="1"
                [max]="maxPercent()"
                step="0.5"
                required
                [ngModel]="percentInput()"
                (ngModelChange)="percentInput.set($event)"
              />
            </div>
            <div class="field min-w-0">
              <label for="shareRewards">Rewards per link</label>
              <input
                id="shareRewards"
                name="shareRewards"
                class="w-28"
                type="number"
                min="1"
                max="100"
                step="1"
                required
                [ngModel]="rewardsInput()"
                (ngModelChange)="rewardsInput.set($event)"
              />
            </div>
            <button uiButton type="submit" [loading]="saving()" [disabled]="saving() || inReview()">
              {{ offer.discount_bps === null ? 'Start the offer' : 'Save the offer' }}
            </button>
            <button
              class="link cursor-pointer border-0 bg-transparent p-0 text-sm text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-primary"
              type="button"
              (click)="editing.set(false)"
            >
              Cancel
            </button>
          </form>
          <p class="help mt-2 mb-0 text-xs text-text-muted">
            Up to {{ maxPercent() }}%. Both discounts come off your proceeds, the same as any code you make.
          </p>
        } @else {
          <div class="mt-4 flex flex-wrap items-center gap-3">
            @if (offer.max_bps > 0) {
              <button uiButton variant="secondary" size="sm" type="button" [disabled]="inReview()" (click)="edit(offer)">
                {{ offer.discount_bps === null ? 'Offer a friend discount' : 'Change the offer' }}
              </button>
            }
            @if (offer.discount_bps !== null) {
              <button
                class="link cursor-pointer border-0 bg-transparent p-0 text-sm text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-danger"
                type="button"
                [disabled]="inReview()"
                (click)="end(offer)"
              >
                End the offer
              </button>
            }
          </div>
        }

        @if (inReview() && session.canManageCodes() && !closed()) {
          <p class="help mt-2 mb-0 text-xs text-text-muted">Changes wait until the review is done.</p>
        }
        @if (error(); as message) {
          <p class="error mt-3 mb-0 text-sm text-danger-text" role="alert">{{ message }}</p>
        }
        @if (notice(); as message) {
          <p class="notice mt-3 mb-0 text-sm text-success" role="status">{{ message }}</p>
        }
      </section>
    }
  `,
})
export class ShareOfferPart {
  private readonly api = inject(Api);
  private readonly shareApi = inject(ShareApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly event = input.required<OrganizerEventDetail>();
  readonly changed = output<OrganizerEventDetail>();

  /** Null for a copy of the event from before the API said (nothing to show). */
  readonly offer = computed<OrganizerShareOffer | null>(() => this.event().share_offer ?? null);

  readonly inReview = computed(() => this.event().status === 'in_review');
  /** A cancelled night, or one whose sales are over, has nobody left to bring a friend. */
  readonly closed = computed(() => this.event().status === 'cancelled' || this.event().sales_ended === true);
  readonly maxPercent = computed(() => (this.offer()?.max_bps ?? 0) / 100);

  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly percentInput = signal<number | string>('');
  readonly rewardsInput = signal<number | string>(5);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  /** Basis points as a person says them: 1500 as "15%", 1250 as "12.5%". */
  percent(bps: number): string {
    return `${Number((bps / 100).toFixed(2))}%`;
  }

  /** Open the form, filled with the offer as it stands (or 10%, five rewards, for a new one). */
  edit(offer: OrganizerShareOffer): void {
    this.error.set(null);
    this.notice.set(null);
    this.percentInput.set(offer.discount_bps === null ? Math.min(10, this.maxPercent()) : offer.discount_bps / 100);
    this.rewardsInput.set(offer.max_rewards);
    this.editing.set(true);
  }

  /** Start or change the offer, once the organizer has said they will fund it. */
  async save(): Promise<void> {
    const offer = this.offer();
    if (!offer) return;

    this.error.set(null);
    this.notice.set(null);

    const percent = Number(this.percentInput());
    const rewards = Number(this.rewardsInput());

    // Said here rather than as a refusal from the server, so nobody confirms
    // a number that is then turned down.
    if (!Number.isFinite(percent) || percent < 1 || percent > this.maxPercent()) {
      this.error.set(`A friend discount is from 1% to ${this.maxPercent()}%.`);

      return;
    }
    if (!Number.isInteger(rewards) || rewards < 1 || rewards > 100) {
      this.error.set('Let each link earn from 1 to 100 rewards.');

      return;
    }

    const bps = Math.round(percent * 100);
    const shown = this.percent(bps);
    const starting = offer.discount_bps === null;

    this.saving.set(true);

    const done = await this.write(
      {
        title: starting ? `Offer friends ${shown} off?` : `Change the friend discount to ${shown}?`,
        body: 'You fund both discounts; they come off your proceeds.',
        consequences: [
          `Each buyer gets a link. A friend who buys through it saves ${shown} on their tickets.`,
          `Once the friend has paid, the buyer is emailed a single-use code for ${shown} off your next events, good for a year — up to ${rewards} per link.`,
          ...(starting ? [] : ['Reward codes already sent keep the percentage they were sent with.']),
        ],
        confirmLabel: starting ? `Offer ${shown} off` : `Change to ${shown}`,
      },
      bps,
      rewards,
    );

    this.saving.set(false);

    if (!done) return;

    this.editing.set(false);
    this.notice.set(starting ? `Friends now save ${shown}. Buyers get their link with their tickets.` : `Friends now save ${shown}.`);
  }

  /** End the offer from now on. What was already handed out stands. */
  async end(offer: OrganizerShareOffer): Promise<void> {
    this.error.set(null);
    this.notice.set(null);

    const done = await this.write(
      {
        title: 'End the friend discount?',
        body: 'Links that buyers already have stop taking anything off from now on.',
        consequences: [
          'Orders that already saved keep their discount.',
          'Reward codes already sent keep working until they expire.',
        ],
        confirmLabel: 'End the offer',
      },
      null,
      offer.max_rewards,
    );

    if (done) this.notice.set('The friend discount has ended.');
  }

  /** Ask, then write and read the event again, handing it to the Overview. */
  private write(
    question: { title: string; body: string; consequences: string[]; confirmLabel: string },
    bps: number | null,
    rewards: number,
  ): Promise<boolean> {
    const eventId = this.event().id;
    let fresh: OrganizerEventDetail | null = null;

    return this.confirmDialog
      .confirm({
        ...question,
        busyLabel: 'Saving…',
        tone: 'default',
        run: () =>
          this.shareApi.setOffer(eventId, bps, rewards).pipe(
            switchMap(() => this.api.event(eventId)),
            tap((event) => (fresh = event)),
          ),
        failure: (response) => messageFor(response, 'The friend discount could not be saved.'),
      })
      .then((done) => {
        if (done && fresh) this.changed.emit(fresh);

        return done;
      });
  }
}
