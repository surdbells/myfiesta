import { Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { ConfirmDialog, UiButton } from '@myfiesta/ui';
import { switchMap, tap } from 'rxjs';
import { Api } from '../../../core/api';
import { Money, OrganizerEventDetail, PayLaterRate, PayLaterSetting } from '../../../core/api.types';
import { messageFor } from '../../../core/errors';
import { formatMoney } from '../../../core/money';
import { SessionStore } from '../../../core/session';
import { PayLaterApi } from '../../pay-later/pay-later-api';

/**
 * Letting buyers pay later with Klarna or Affirm (`pay_later_enabled`), an
 * opt-in with a confirmation naming the fee, on the event's Settings.
 *
 * The PAY track's own file. Settings places it once, below its form, and
 * never edits it again. Outside the form on purpose: it saves on its own,
 * after its confirmation, rather than with "Save changes".
 *
 * The lenders charge about twice what a card does, and the organizer pays
 * the difference on every order paid that way, so turning it on says what
 * each charges beside a card before anything changes. Those rates come from
 * GET /organizer/events/{id}/pay-later, which also says whether myFiesta
 * offers paying later on this night at all: where it does not (switched off,
 * or not in Canadian dollars) and the night was never opted in, the part
 * draws nothing, since there is nothing to choose.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras). Settings keeps
 * it without refilling its form, so unsaved edits there survive.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-pay-later-part',
  host: { class: 'contents' },
  imports: [UiButton],
  template: `
    @if (shown(); as s) {
      <section
        class="pay-later card mt-8 max-w-[44rem] p-6"
        aria-labelledby="pay-later-heading"
      >
        <h2 id="pay-later-heading" class="section mb-1 text-base font-semibold">Pay over time</h2>

        @if (s.enabled) {
          <p class="m-0 text-sm">
            Buyers can choose Klarna or Affirm on the payment page{{ s.offered_now ? '' : ' from ' + s.max_days_before_event + ' days before the night' }}.
            You pay what the lender charges over a card on each order paid that way.
          </p>
        } @else {
          <p class="m-0 text-sm text-text-muted">
            Let buyers spread the cost with Klarna or Affirm, chosen on the payment page. The lenders charge more
            than a card does, and you pay the difference on each order paid that way.
          </p>
        }

        @if (!s.available) {
          <p class="help mt-3 mb-0 text-xs text-text-muted">
            Paying later is switched off on myFiesta at the moment, so buyers are not offered it.
          </p>
        }

        @if (session.canEditEvents()) {
          <div class="mt-4 flex flex-wrap items-center gap-3">
            @if (s.enabled) {
              <button uiButton variant="secondary" size="sm" type="button" [loading]="saving()" [disabled]="saving()" (click)="turnOff()">
                Stop offering it
              </button>
            } @else {
              <button uiButton variant="secondary" size="sm" type="button" [loading]="saving()" [disabled]="saving()" (click)="turnOn(s)">
                Offer pay over time
              </button>
            }
          </div>
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
export class PayLaterPart {
  private readonly api = inject(Api);
  private readonly payLaterApi = inject(PayLaterApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly event = input.required<OrganizerEventDetail>();
  readonly changed = output<OrganizerEventDetail>();

  /** Its own signal, so the event coming back after a save does not read the setting again. */
  private readonly eventId = computed(() => this.event().id);

  readonly setting = signal<PayLaterSetting | null>(null);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  /** The setting, where there is something to choose or something to turn off. */
  readonly shown = computed(() => {
    const setting = this.setting();

    return setting && (setting.available || setting.enabled) ? setting : null;
  });

  constructor() {
    effect((onCleanup) => {
      const id = this.eventId();

      untracked(() => {
        this.setting.set(null);
        const read = this.payLaterApi.setting(id).subscribe({
          next: (setting) => this.setting.set(setting),
          // Nothing to show is better than a box that cannot say anything.
          error: () => this.setting.set(null),
        });
        onCleanup(() => read.unsubscribe());
      });
    });
  }

  /** "5.99% + $0.30": a rate as Stripe publishes it. */
  rate(rate: PayLaterRate, currency: Money['currency']): string {
    const percent = `${Number((rate.bps / 100).toFixed(2))}%`;

    return rate.flat > 0 ? `${percent} + ${formatMoney({ amount: rate.flat, currency })}` : percent;
  }

  /** What paying later costs over a card, at most, for one order: "3.1%". */
  difference(setting: PayLaterSetting): string {
    const { card, klarna, affirm } = setting.fees;
    const bps = Math.max(klarna.bps, affirm.bps) - card.bps;
    const flat = Math.max(klarna.flat, affirm.flat) - card.flat;
    const percent = `${Number((Math.max(0, bps) / 100).toFixed(1))}%`;

    return flat > 0 ? `${percent} + ${formatMoney({ amount: flat, currency: setting.currency })}` : percent;
  }

  /** Turn it on, once the organizer has been told what it costs them. */
  async turnOn(setting: PayLaterSetting): Promise<void> {
    const { card, klarna, affirm } = setting.fees;
    const currency = setting.currency;

    const done = await this.write(true, {
      title: 'Let buyers pay over time?',
      body:
        `Klarna charges ${this.rate(klarna, currency)} and Affirm ${this.rate(affirm, currency)} of each order paid with them, ` +
        `where a card costs ${this.rate(card, currency)}. You pay the difference, about ${this.difference(setting)} of each such order, ` +
        'taken off your proceeds.',
      consequences: [
        `Buyers see Klarna and Affirm on the payment page from ${setting.max_days_before_event} days before the night, for orders the lender accepts.`,
        'Buyers pay the same price however they pay.',
        'Refunds go back through Affirm for 120 days after the payment and Klarna for 180. After that, myFiesta support returns the money another way.',
      ],
      confirmLabel: 'Offer pay over time',
    });

    if (done) {
      this.notice.set(
        this.setting()?.offered_now
          ? 'Buyers can now choose Klarna or Affirm on the payment page.'
          : `Buyers will see Klarna and Affirm from ${setting.max_days_before_event} days before the night.`,
      );
    }
  }

  /** Stop offering it. Orders already paid that way keep their terms. */
  async turnOff(): Promise<void> {
    const done = await this.write(false, {
      title: 'Stop offering pay over time?',
      body: 'Buyers pay by card, wallet or Link from now on.',
      consequences: ['Orders already paid with Klarna or Affirm are not affected, and their fee difference still applies.'],
      confirmLabel: 'Stop offering it',
    });

    if (done) this.notice.set('Buyers are no longer offered Klarna or Affirm.');
  }

  /** Ask, then write and read the event again, handing it to Settings. */
  private async write(
    enabled: boolean,
    question: { title: string; body: string; consequences: string[]; confirmLabel: string },
  ): Promise<boolean> {
    const eventId = this.eventId();
    let fresh: OrganizerEventDetail | null = null;

    this.error.set(null);
    this.notice.set(null);
    this.saving.set(true);

    const done = await this.confirmDialog.confirm({
      ...question,
      busyLabel: 'Saving…',
      tone: 'default',
      run: () =>
        this.payLaterApi.set(eventId, enabled).pipe(
          tap((setting) => this.setting.set(setting)),
          switchMap(() => this.api.event(eventId)),
          tap((event) => (fresh = event)),
        ),
      failure: (response) => messageFor(response, 'That could not be saved.'),
    });

    this.saving.set(false);

    if (done && fresh) this.changed.emit(fresh);

    return done;
  }
}
