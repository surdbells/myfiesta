import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { AboutYou, AnswerValue, EventDetail, Money, Quote, ReceiptTax } from '../../core/api.types';
import { attendeesFor, missing, slotsFor, withAnswer } from '../../core/checkout-answers';
import { CheckoutStore } from '../../core/checkout-store';
import { EmbedMode, rememberPayment } from '../../core/embed';
import { formatMoney, formatPrice } from '../../core/money';
import { Seo } from '../../core/seo';
import { CheckoutSteps } from '../../shared/checkout-steps';
import { AboutYouPart } from './parts/about-you-part';
import { FriendDiscountPart } from './parts/friend-discount-part';
import { PayLaterPart } from './parts/pay-later-part';
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
  // The parts are each a feature's own file, placed once in the template.
  imports: [FormsModule, RouterLink, CheckoutSteps, QuestionField, AboutYouPart, PayLaterPart, FriendDiscountPart],
  templateUrl: './checkout.html',
})
export class Checkout {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);
  readonly store = inject(CheckoutStore);
  readonly embed = inject(EmbedMode);

  readonly event = signal<EventDetail | null>(null);
  /** The API says there is no such event on sale. Answered 404 on the server. */
  readonly notFound = signal(false);
  /** The API did not answer, which is not the same as there being no event. */
  readonly unavailable = signal(false);
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

  /**
   * The terms, the privacy policy and the refund policy, agreed to.
   *
   * Unticked every time. Nobody is signed in on this site, so there is no
   * earlier agreement to remember — and a box ticked for somebody is not
   * somebody agreeing. Sent as it stands; the server refuses the order
   * without it and keeps which version was agreed to, with the order.
   */
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

  /**
   * What the buyer chose to say under "About you (optional)", from that
   * part, sent with the order as it stands. Null, which sends nothing, until
   * the part is filled in and the buyer says something.
   */
  readonly aboutYou = signal<AboutYou | null>(null);

  readonly formatMoney = formatMoney;
  readonly formatPrice = formatPrice;

  readonly slug = this.route.snapshot.paramMap.get('slug')!;

  /**
   * Nothing to pay, so the middle step is only details.
   *
   * The server's word once the basket is priced. Before that, the prices
   * already on the page — the event's, for what is in the basket — so a free
   * night reads "Your details" from the first paint. It read "Details &
   * payment" until the quote came back, and then changed. Only a code can make
   * a priced basket free, and only the quote can say so.
   */
  readonly free = computed(() => {
    const quote = this.quote();
    if (quote) return quote.requires_payment === false;

    const event = this.event();
    if (!event) return false;

    const tiers = [...(this.store.access()?.ticket_types ?? []), ...(event.ticket_types ?? [])];
    // A price the page does not have counts as a price: the quote will say.
    const costs = (priced: { id: string; price: Money }[], id: string) =>
      priced.find((item) => item.id === id)?.price.amount !== 0;

    return (
      this.store.lines().length > 0 &&
      !this.store.lines().some((line) => costs(tiers, line.ticket_type_id)) &&
      !this.store.addOnLines().some((line) => costs(event.add_ons ?? [], line.add_on_id))
    );
  });

  readonly buyerQuestions = computed(() =>
    (this.event()?.questions ?? []).filter((question) => !question.per_attendee),
  );

  readonly attendeeQuestions = computed(() =>
    (this.event()?.questions ?? []).filter((question) => question.per_attendee),
  );

  /** One slot per ticket being bought, in the order the server will mint them. */
  readonly slots = computed(() => slotsFor(this.quote()?.lines ?? []));

  /**
   * Each tax on the tickets, on a line of its own: GST and QST side by side
   * in Quebec rather than one figure labelled "GST + QST", so the bill here
   * reads line for line like the receipt that follows it.
   *
   * Not a tax that came to nothing. The server lists the rate whatever it
   * was charged on, so free tickets in Toronto still carry "HST 13%" against
   * nothing; this page never showed a tax of $0.00 and does not start now.
   */
  readonly ticketTaxes = computed(() => this.taxesOn('tickets'));

  /** The tax on the service fee, where it is taxed. It used to be in the fee's figure, unsaid. */
  readonly feeTaxes = computed(() => this.taxesOn('service_charge'));

  /**
   * The service fee before any tax added to it, which has its own line
   * beneath — as on the receipt, so the lines still add up to the total.
   * Tax inside the fee, the way Nigerian prices hold VAT, stays in the figure
   * and is shown as included.
   */
  readonly serviceFee = computed<Money | null>(() => {
    const quote = this.quote();
    if (!quote) return null;

    const added = this.feeTaxes()
      .filter((tax) => !tax.included)
      .reduce((sum, tax) => sum + tax.amount.amount, 0);

    return { amount: quote.service_charge.amount - added, currency: quote.service_charge.currency };
  });

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

  private taxesOn(on: ReceiptTax['on']): ReceiptTax[] {
    return (this.quote()?.tax_lines ?? []).filter((tax) => tax.on === on && tax.amount.amount > 0);
  }

  /** "GST 5%", "QST 9.975%", "VAT 7.5%, included" — worded as the receipt words it. */
  taxLabel(tax: ReceiptTax): string {
    return `${tax.name} ${tax.rate}%${tax.on === 'service_charge' ? ' on the service fee' : ''}${tax.included ? ', included' : ''}`;
  }

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

    /*
     * Arriving with nothing chosen — a bookmark, an expired session, and
     * always on the server, which has no basket — goes back to the choosing,
     * not to an empty bill.
     *
     * Once the event is known to be there. For a slug that is no event on
     * sale there is no choosing to go back to, and this address answers 404
     * itself — the way the event page does — rather than a 200 that says
     * "not found", or a redirect to a page that does. An API that did not
     * answer is a 503 instead: a page to come back to, not a page that is gone.
     */
    const empty = this.store.lines().length === 0;

    this.api.event(this.slug).subscribe({
      next: ({ data }) => {
        if (empty) {
          void this.router.navigate(this.embed.tickets(this.slug));

          return;
        }

        this.event.set(data);
      },
      error: (error: HttpErrorResponse) => {
        if (error.status === 404) {
          this.notFound.set(true);
          this.seo.notFound('Event not found');
        } else {
          this.unavailable.set(true);
          this.seo.unavailable('Event unavailable');
        }
      },
    });

    if (empty) return;

    this.promo.set(this.store.code());
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

    // Inside a frame, payment gets a tab of its own — processors refuse to be
    // framed. Opened now, while the click still counts as the buyer's: a tab
    // opened after the order comes back is one the browser blocks as a popup.
    const payTab = this.embed.active() && (this.quote()?.total.amount ?? 0) > 0 ? this.openPaymentTab() : null;

    const name = `${this.first().trim()} ${this.last().trim()}`.trim();

    this.api
      .order(this.slug, {
        // "About you", when the buyer said anything: fields of its own, and
        // first, so none of them can stand in for one of the order's below.
        ...this.aboutYou(),
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
        embedded: this.embed.active() || undefined,
        accept_terms: this.agreed(),
        // The last press's order, when the payment page could not be opened
        // for it: this one takes over its hold, so pressing again during a
        // processor outage does not hold the same places twice over.
        retry_of: this.store.unpaid() ?? undefined,
      })
      .subscribe({
        next: (order) => {
          this.store.clear(this.slug);

          if (order.payment && this.embed.active()) {
            // The frame waits on the order while the tab takes the money.
            // Kept, for a browser that refused the tab: the order page offers
            // it again from a click of the buyer's own.
            rememberPayment(order.reference, order.payment.redirect_url);
            if (payTab) payTab.location.href = order.payment.redirect_url;
            void this.router.navigate(this.embed.order(order.reference));
          } else if (order.payment) {
            // The processor's page, same tab. The order page picks the story
            // back up when they return.
            window.location.href = order.payment.redirect_url;
          } else {
            payTab?.close();
            void this.router.navigate(this.embed.order(order.reference));
          }
        },
        error: (response) => {
          payTab?.close();
          this.placing.set(false);

          // An order was placed, and only its payment page failed: the next
          // press names it (retry_of) and inherits its hold.
          const reference = response?.error?.reference;
          if (typeof reference === 'string' && reference !== '') this.store.setUnpaid(this.slug, reference);

          this.orderError.set(
            response?.error?.message ??
              'That order could not be placed. Nothing has been charged — please try again.',
          );
        },
      });
  }

  /**
   * A blank tab to send the buyer to payment in, or null if the browser said no.
   *
   * Cut loose from this page before it goes anywhere: the processor's page
   * should not be able to reach back into a frame on somebody else's site.
   */
  private openPaymentTab(): Window | null {
    const tab = window.open('', '_blank');
    if (!tab) return null;

    tab.opener = null;
    tab.document.title = 'Opening payment…';
    tab.document.body.textContent = 'Opening secure payment…';

    return tab;
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
