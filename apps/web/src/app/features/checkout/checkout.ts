import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { AnswerValue, EventDetail, Quote } from '../../core/api.types';
import { attendeesFor, missing, slotsFor, withAnswer } from '../../core/checkout-answers';
import { CheckoutStore } from '../../core/checkout-store';
import { formatMoney } from '../../core/money';
import { CheckoutSteps } from '../../shared/checkout-steps';
import { QuestionField } from './question-field';

/**
 * Step two: who the tickets are for, and the itemized money.
 *
 * There is deliberately no card form on this page. Payment happens on the
 * processor's own page — Stripe for CAD, Paystack for NGN — so a card number
 * never touches this origin; what this page owes the buyer is the full bill
 * before they are sent there: subtotal, discount, service charge, tax, total,
 * every line priced by the server.
 */
@Component({
  selector: 'mf-checkout',
  standalone: true,
  imports: [FormsModule, RouterLink, CheckoutSteps, QuestionField],
  templateUrl: './checkout.html',
})
export class Checkout {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly store = inject(CheckoutStore);

  readonly event = signal<EventDetail | null>(null);
  readonly quote = signal<Quote | null>(null);
  readonly quoteError = signal<string | null>(null);

  /** Why the code typed was not accepted, shown beside the code field. */
  readonly codeError = signal<string | null>(null);
  readonly orderError = signal<string | null>(null);
  readonly placing = signal(false);

  readonly first = signal('');
  readonly last = signal('');
  readonly email = signal('');
  readonly confirm = signal('');
  readonly agreed = signal(false);

  readonly promo = signal('');

  /**
   * What the organizer asked, and what has been typed so far.
   *
   * Two stores, because the questions are asked of two different things: once
   * for the order, and once about each person on it. The per-person answers
   * are keyed by the slot they belong to rather than by a ticket — there are
   * no tickets yet, and will not be until a payment settles.
   */
  readonly orderAnswers = signal<Record<string, AnswerValue>>({});
  readonly attendeeAnswers = signal<Record<string, Record<string, AnswerValue>>>({});

  readonly formatMoney = formatMoney;

  readonly slug = this.route.snapshot.paramMap.get('slug')!;

  readonly buyerQuestions = computed(() =>
    (this.event()?.questions ?? []).filter((question) => !question.per_attendee),
  );

  readonly attendeeQuestions = computed(() =>
    (this.event()?.questions ?? []).filter((question) => question.per_attendee),
  );

  /** One slot per ticket being bought, in the order the server will mint them. */
  readonly slots = computed(() => slotsFor(this.quote()?.lines ?? []));

  /**
   * Whether every question that has to be answered has been.
   *
   * The server decides; this is the same rule applied early, so the answer
   * arrives beside the field rather than at the payment step.
   */
  readonly answersComplete = computed(
    () =>
      !missing(this.event()?.questions ?? [], this.orderAnswers(), this.slots(), this.attendeeAnswers()),
  );

  readonly emailsDisagree = computed(
    () =>
      this.confirm().trim() !== '' &&
      this.email().trim().toLowerCase() !== this.confirm().trim().toLowerCase(),
  );

  readonly ready = computed(
    () =>
      this.first().trim() !== '' &&
      this.email().trim() !== '' &&
      !this.emailsDisagree() &&
      this.confirm().trim() !== '' &&
      this.agreed() &&
      this.answersComplete() &&
      this.quote() !== null,
  );

  /** What has been said so far, for one question in one place. */
  answerFor(questionId: string, slotKey = ''): AnswerValue | null {
    const answers = slotKey ? (this.attendeeAnswers()[slotKey] ?? {}) : this.orderAnswers();

    return answers[questionId] ?? null;
  }

  setAnswer(questionId: string, value: AnswerValue | null, slotKey = ''): void {
    if (slotKey === '') {
      this.orderAnswers.set(withAnswer(this.orderAnswers(), questionId, value));

      return;
    }

    this.attendeeAnswers.set({
      ...this.attendeeAnswers(),
      [slotKey]: withAnswer(this.attendeeAnswers()[slotKey] ?? {}, questionId, value),
    });
  }


  constructor() {
    this.store.loadFor(this.slug);

    // Arriving with nothing chosen — a bookmark, an expired session — goes
    // back to the choosing, not to an empty bill.
    if (this.store.lines().length === 0) {
      void this.router.navigate(['/', this.slug, 'tickets']);
      return;
    }

    this.promo.set(this.store.code());

    this.api.event(this.slug).subscribe({
      next: ({ data }) => this.event.set(data),
      error: () => undefined,
    });

    this.refreshQuote();
  }

  applyCode(): void {
    this.store.setCode(this.slug, this.promo().trim());
    this.refreshQuote();
  }

  clearCode(): void {
    this.promo.set('');
    this.store.setCode(this.slug, '');
    this.refreshQuote();
  }

  placeOrder(): void {
    if (!this.ready() || this.placing()) return;

    this.placing.set(true);
    this.orderError.set(null);

    const name = `${this.first().trim()} ${this.last().trim()}`.trim();

    this.api
      .order(this.slug, {
        items: this.store.lines(),
        add_ons: this.store.addOnLines(),
        buyer: { name, email: this.email().trim() },
        code: this.store.code() || undefined,
        ref: this.store.ref() ?? undefined,
        access_code: this.store.access()?.code,
        answers: this.orderAnswers(),
        // Only when there is something to say about each person. An empty
        // list would still have to match the basket, and matching it for no
        // reason is a way to fail an order over a question nobody asked.
        attendees: this.attendeeQuestions().length > 0 ? attendeesFor(this.slots(), this.attendeeAnswers()) : undefined,
      })
      .subscribe({
        next: (order) => {
          this.store.clear(this.slug);

          if (order.payment) {
            // The processor's page, same tab. The order page picks the story
            // back up when they return.
            window.location.href = order.payment.redirect_url;
          } else {
            void this.router.navigate(['/order', order.reference]);
          }
        },
        error: (response) => {
          this.placing.set(false);
          this.orderError.set(
            response?.error?.message ??
              'That order could not be placed. Nothing has been charged — please try again.',
          );
        },
      });
  }

  /**
   * Price the basket.
   *
   * A code the server refuses is a problem with the code, not the basket. It
   * used to replace the whole summary with the refusal — the lines, the code
   * field and the way to remove the code all went with it, and so did the
   * way to pay. Now the basket is priced again without it, the code stays in
   * the field for fixing, and the reason is said beside it.
   */
  private refreshQuote(afterRefusedCode = false): void {
    this.quoteError.set(null);

    const code = this.store.code() || undefined;

    this.api
      .quote(this.slug, {
        items: this.store.lines(),
        add_ons: this.store.addOnLines(),
        code,
        ref: this.store.ref() ?? undefined,
        access_code: this.store.access()?.code,
      })
      .subscribe({
        next: (quote) => {
          this.quote.set(quote);
          if (!afterRefusedCode) this.codeError.set(null);
        },
        error: (response) => {
          const message = response?.error?.message ?? 'We could not price that basket.';

          // Only a refusal (422) is about the code; a dropped connection is not
          // a reason to take somebody's code away.
          if (code && response?.status === 422) {
            this.codeError.set(message);
            this.store.setCode(this.slug, '');
            this.refreshQuote(true);

            return;
          }

          // Still failing without the code: the basket was the problem after all.
          if (afterRefusedCode) this.codeError.set(null);

          this.quote.set(null);
          this.quoteError.set(message);
        },
      });
  }
}
