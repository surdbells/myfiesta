<?php

namespace App\Services\Disputes;

use App\Contracts\Payments\ProcessorDispute;
use App\Models\Order;
use App\Models\PaymentEvidence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The answer to a dispute, put together from the records, field by field.
 *
 * Every sentence is built from a fact in the case file and says only that
 * fact: nothing is written that a record does not show, and a record that is
 * missing leaves its sentence out and its line on the checklist unticked. So
 * a draft can be thin — a guest who never opened their tickets, a payment the
 * processor never described — and the page says so, rather than filling the
 * gap with something that sounds right.
 *
 * Which fields there are follows the reason (Reasons::kind) and the processor.
 * Stripe's are its own evidence fields, named as its API names them; Paystack
 * takes a handful of its own and one document.
 */
final class EvidenceDraft
{
    /** The checkout's terms box, word for word (DisputeAnswerTest holds the site to it). */
    public const CHECKBOX = 'I accept the terms, the privacy policy and the refund policy.';

    /**
     * Every field either processor takes, how staff see it, and how long it
     * may be. Stripe allows up to 20,000 characters in a field and 150,000 in
     * all; the longest here stay inside both.
     */
    public const FIELDS = [
        // Stripe
        'product_description' => ['label' => 'What was sold', 'long' => true, 'max' => 5000],
        'customer_name' => ['label' => 'Buyer\'s name', 'long' => false, 'max' => 500],
        'customer_email_address' => ['label' => 'Buyer\'s email address', 'long' => false, 'max' => 500],
        'customer_purchase_ip' => ['label' => 'Internet address the order came from', 'long' => false, 'max' => 100],
        'service_date' => ['label' => 'When the night took place', 'long' => false, 'max' => 500],
        'access_activity_log' => ['label' => 'Access log: tickets opened and scanned', 'long' => true, 'max' => 20000],
        'refund_policy_disclosure' => ['label' => 'How the refund policy was shown and accepted', 'long' => true, 'max' => 5000],
        'refund_refusal_explanation' => ['label' => 'Why no refund was due', 'long' => true, 'max' => 5000],
        'duplicate_charge_id' => ['label' => 'The other charge (Stripe\'s id)', 'long' => false, 'max' => 200],
        'duplicate_charge_explanation' => ['label' => 'Why the two charges are two orders', 'long' => true, 'max' => 5000],
        'uncategorized_text' => ['label' => 'Summary, in the order things happened', 'long' => true, 'max' => 20000],
        // Paystack
        'customer_email' => ['label' => 'Buyer\'s email address', 'long' => false, 'max' => 500],
        'customer_phone' => ['label' => 'Buyer\'s phone number (Paystack requires it)', 'long' => false, 'max' => 100],
        'service_details' => ['label' => 'What was sold, and what happened', 'long' => true, 'max' => 20000],
        'delivery_date' => ['label' => 'Date the tickets were delivered (YYYY-MM-DD)', 'long' => false, 'max' => 10],
        'message' => ['label' => 'Note with the answer', 'long' => true, 'max' => 1000],
    ];

    /** Fields Paystack will not take evidence without. */
    public const PAYSTACK_REQUIRED = ['customer_email', 'customer_name', 'customer_phone', 'service_details', 'message'];

    /** The documents, and what each is called. */
    public const DOCUMENTS = [
        'receipt' => 'Receipt',
        'service_documentation' => 'Delivery, access and entry',
        'refund_policy' => 'Refund policy as shown, and its acceptance',
        'customer_communication' => 'Emails sent to the buyer',
        'evidence_pack' => 'All of the above, in one document',
    ];

    /** Where a field was cut, so nobody reads the end of it as the end of the story. */
    public const SHORTENED = "\n[shortened to fit; the documents have the rest]";

    public function __construct(private readonly CompellingEvidence $compelling) {}

    /**
     * @return array{kind: string, fields: array<string, string>, files: list<string>, checklist: list<array{key: string, label: string, found: bool, detail: string|null}>, cautions: list<string>, compelling_evidence: array<string, mixed>|null}
     */
    public function build(CaseFile $case, ?ProcessorDispute $processor = null): array
    {
        $reason = $processor->reason ?? $case->dispute->reason;
        $kind = Reasons::kind($reason);
        $stripe = $case->gateway() === 'stripe';

        $compelling = $stripe && $kind === Reasons::FRAUD
            ? $this->compelling->assess($case, $processor->enhancedEligibility ?? [], $processor->facts['evidence_details']['enhanced_eligibility'][CompellingEvidence::TYPE]['status'] ?? null)
            : null;

        return [
            'kind' => $kind,
            'fields' => $stripe ? $this->stripeFields($case, $kind) : $this->paystackFields($case, $kind, $processor),
            'files' => $stripe ? $this->stripeFiles($case, $kind) : ['evidence_pack'],
            'checklist' => $this->checklist($case, $kind, $compelling),
            'cautions' => $this->cautions($case, $kind),
            'compelling_evidence' => $compelling,
        ];
    }

    // --- Stripe ---------------------------------------------------------------------

    /** @return array<string, string> */
    private function stripeFields(CaseFile $case, string $kind): array
    {
        $order = $case->order;

        $fields = [
            'product_description' => $this->productDescription($case),
            'customer_name' => (string) $order->buyer_name,
            'customer_email_address' => (string) $order->buyer_email,
            'customer_purchase_ip' => $kind === Reasons::FRAUD ? (string) $order->purchase_ip : '',
            'service_date' => (string) $case->eventWhen(),
            'access_activity_log' => $case->accessLog(),
        ];

        if (in_array($kind, [Reasons::REFUND, Reasons::GENERAL], true)) {
            $fields['refund_policy_disclosure'] = $this->disclosure($case);
            $fields['refund_refusal_explanation'] = $this->noRefundDue($case);
        }

        if ($kind === Reasons::DUPLICATE) {
            [$id, $explanation] = $this->duplicate($case);
            $fields['duplicate_charge_id'] = $id;
            $fields['duplicate_charge_explanation'] = $explanation;
        }

        $fields['uncategorized_text'] = $this->summary($case, $kind);

        return $this->fit(array_filter($fields, fn (string $value) => trim($value) !== ''));
    }

    /** @return list<string> */
    private function stripeFiles(CaseFile $case, string $kind): array
    {
        return array_values(array_filter([
            'receipt',
            'service_documentation',
            $case->emailsToBuyer()->isNotEmpty() ? 'customer_communication' : null,
            in_array($kind, [Reasons::REFUND, Reasons::GENERAL], true) && $case->policy !== null ? 'refund_policy' : null,
        ]));
    }

    // --- Paystack -------------------------------------------------------------------

    /** @return array<string, string> */
    private function paystackFields(CaseFile $case, string $kind, ?ProcessorDispute $processor): array
    {
        $order = $case->order;
        $delivered = $case->emailsToBuyer()->first()->occurred_at ?? $case->issuedAt();

        $details = array_filter([
            $this->productDescription($case),
            $this->summary($case, $kind),
            in_array($kind, [Reasons::REFUND, Reasons::GENERAL], true) ? $this->disclosure($case) : null,
            in_array($kind, [Reasons::REFUND, Reasons::GENERAL], true) ? $this->noRefundDue($case) : null,
        ]);

        return $this->fit([
            'customer_name' => (string) $order->buyer_name,
            'customer_email' => (string) $order->buyer_email,
            'customer_phone' => (string) ($order->buyer_phone ?: ($processor->facts['customer_phone'] ?? '')),
            'service_details' => implode("\n\n", $details),
            'delivery_date' => $delivered === null ? '' : CarbonImmutable::instance($delivered)->utc()->format('Y-m-d'),
            'message' => $this->note($case),
        ]);
    }

    /** A line or two for Paystack's own note, from the same facts. */
    private function note(CaseFile $case): string
    {
        $tickets = $case->tickets->count();
        $issued = $case->issuedAt();
        $admitted = $case->ticketsAdmitted();

        return implode(' ', array_filter([
            $tickets > 0 && $issued !== null
                ? "{$tickets} ticket(s) for {$case->eventTitle()} were issued on ".CaseFile::at($issued).($case->emailsToBuyer()->isNotEmpty() ? ' and emailed to the buyer.' : '.')
                : null,
            $admitted > 0 ? "{$admitted} of {$tickets} were scanned in at the door." : null,
            'The attached document has the order, the delivery and entry records, and the refund policy the buyer accepted.',
        ]));
    }

    // --- the words --------------------------------------------------------------------

    private function productDescription(CaseFile $case): string
    {
        return 'Tickets to '.$case->eventTitle()
            .($case->eventWhen() ? ', a live event on '.$case->eventWhen() : '')
            .($case->eventWhere() ? ', at '.$case->eventWhere() : '')
            .($case->organizer() ? ', organized by '.$case->organizer() : '')
            .', sold through myFiesta: '.$case->whatWasBought().', '.$case->total().' in all (order '.$case->order->reference.').'
            .' Tickets are delivered by email and in the myFiesta app, and are checked by scanning at the door.';
    }

    /**
     * How the refund policy was put in front of the buyer, and how they said
     * yes — only what the order and the processor recorded.
     */
    private function disclosure(CaseFile $case): string
    {
        $order = $case->order;

        if ($order->terms_accepted_at === null) {
            return '';
        }

        $site = rtrim((string) config('app.public_url'), '/');

        return implode(' ', array_filter([
            'The order was placed through the myFiesta checkout'.($site !== '' ? ' ('.$site.')' : '').', which will not take an order until the buyer ticks a box — unticked to begin with — beside the words "'.self::CHECKBOX.'"'
                .($site !== '' ? ', each linked to its page ('.$site.'/terms, '.$site.'/privacy, '.$site.'/refunds).' : '.'),
            $case->policySummary !== null ? 'Directly beneath the box, the refund policy was summed up in one sentence: "'.$case->policySummary.'"' : null,
            $case->policySummary !== null && $case->gateway() === 'stripe' ? 'The same sentence was shown beside the pay button on Stripe\'s payment page.' : null,
            'The buyer ticked the box and accepted version '.$order->terms_version.' of the terms, the privacy policy and the refund policy at '.CaseFile::at($order->terms_accepted_at).'.',
            $case->stripeConsent() === 'accepted' ? 'Stripe also recorded that the buyer ticked Stripe\'s own terms of service box on its payment page.' : null,
            $case->policy !== null ? 'The refund policy as it stood under that version is attached.' : null,
        ]));
    }

    /**
     * Why nothing was owed, when nothing was: the policy's own sentence, the
     * night not cancelled, and what was refunded. Nothing at all when the
     * night was cancelled — then something was owed.
     */
    private function noRefundDue(CaseFile $case): string
    {
        if ($case->wasCancelled() || $case->order->terms_accepted_at === null) {
            return '';
        }

        $refunded = $case->refunded();
        $admitted = $case->ticketsAdmitted();

        return implode(' ', array_filter([
            $case->policySummary !== null
                ? 'The refund policy the buyer accepted says: "'.$case->policySummary.'"'
                : 'The refund policy the buyer accepted is attached.',
            $case->hasHappened()
                ? $case->eventTitle().' was not cancelled.'.($case->completion !== null ? ' '.$case->completionLine() : '')
                : $case->eventTitle().' has not been cancelled'.($case->eventWhen() ? ' and takes place on '.$case->eventWhen() : '').'.',
            $admitted > 0 ? "The buyer's tickets were used: {$admitted} of {$case->tickets->count()} scanned in at the door." : null,
            $refunded > 0
                ? $case->money($refunded).' of '.$case->total().' was refunded through myFiesta.'
                : 'No refund was made through myFiesta for this order.',
        ]));
    }

    /**
     * The other order a "charged twice" claim is about.
     *
     * @return array{0: string, 1: string}
     */
    private function duplicate(CaseFile $case): array
    {
        $other = $case->sameNight->first();

        if ($other === null) {
            return ['', ''];
        }

        $record = PaymentEvidence::query()->where('order_id', $other->id)->where('status', PaymentEvidence::CAPTURED)->first();
        $charge = (string) ($record->facts['charge']['id'] ?? '');

        $describe = fn (Order $order) => 'order '.$order->reference.', paid at '.CaseFile::at($order->paid_at).', for '
            .$order->lines()->get()->map(fn ($line) => $line->quantity.' × '.$line->name)->implode(', ')
            .' ('.$case->money((int) $order->total_amount, $order->currency).')';

        return [
            $charge,
            'These are two separate purchases, not one charged twice. The buyer placed '.($case->sameNight->count() + 1).' orders for '.$case->eventTitle().': '
                .$describe($case->order).'; and '.$describe($other).'. Each went through checkout on its own, was paid on its own'
                .' and has its own tickets.'.($charge !== '' ? ' The other charge is '.$charge.'.' : ''),
        ];
    }

    /**
     * The executive summary: short, factual, in the order things happened.
     */
    private function summary(CaseFile $case, string $kind): string
    {
        $order = $case->order;
        $events = [];
        $at = function (?CarbonInterface $moment, string $text) use (&$events): void {
            if ($moment !== null) {
                $events[] = [$moment, CaseFile::at($moment).': '.$text];
            }
        };

        $at($order->created_at, $order->buyer_name.' ('.$order->buyer_email.') placed the order online'
            .($order->purchase_ip ? ' from internet address '.$order->purchase_ip : '').'.');

        if ($order->terms_accepted_at !== null) {
            $at($order->terms_accepted_at, 'accepted the terms, the privacy policy and the refund policy (version '.$order->terms_version.') by ticking the box at checkout.');
        }

        $paid = 'paid '.$case->total().($case->cardLine() ? ' by '.$case->cardLine() : '').' through '.$case->processorName().'.';

        if ($case->authenticated()) {
            $paid .= ' The card\'s bank authenticated the cardholder with 3D Secure: '.$case->threeDSecureLine().'.';
        } elseif ($case->threeDSecureLine() !== null) {
            $paid .= ' 3D Secure: '.$case->threeDSecureLine().'.';
        }

        if ($kind === Reasons::FRAUD) {
            $paid .= ($case->checksLine() ? ' Card checks: '.$case->checksLine().'.' : '')
                .($case->riskLine() ? ' Stripe\'s fraud checks: '.$case->riskLine().'.' : '');
        }

        if ($case->statementDescriptor() !== null) {
            $paid .= ' It appears on the statement as "'.$case->statementDescriptor().'".';
        }

        $at($order->paid_at, $paid);
        $at($case->issuedAt(), $case->tickets->count().' ticket(s) issued.');

        $emails = $case->emailsToBuyer();

        if ($emails->isNotEmpty()) {
            $at($emails->first()->occurred_at, 'tickets emailed to '.$order->buyer_email.($emails->count() > 1 ? ' ('.$emails->count().' emails to the buyer in all)' : '').'.');
        }

        $openings = $case->openings();

        if ($openings->isNotEmpty()) {
            $first = $openings->first();
            // Only the buyer's own openings say where from: a ticket passed
            // on and shown in its new holder's app names nobody's address.
            $from = $case->addressOf($first);
            $sameAddress = $order->purchase_ip !== null
                && $openings->contains(fn ($row) => $case->addressOf($row) === $order->purchase_ip);

            $at($first->occurred_at, 'the tickets were first opened'.($from !== null ? ' from '.$from : '')
                .'; opened or shown '.$openings->count().' time(s) in all'
                .($sameAddress ? ', including from the internet address the order came from' : '').'.');
        }

        if ($case->transfers->isNotEmpty()) {
            $at($case->transfers->first()->transferred_at, $case->transfers->count().' ticket(s) passed on by the buyer to somebody else.');
        }

        $admissions = $case->admissions();

        if ($admissions->isNotEmpty()) {
            $at($admissions->first()->scanned_at, $case->ticketsAdmitted().' of '.$case->tickets->count().' ticket(s) scanned in at the door (first scan by '.$case->scannedBy($admissions->first()).').');
        }

        if ($case->completion !== null) {
            $at($case->completion->recorded_at, 'the night was recorded as having taken place: '.$case->completionLine());
        }

        foreach ($case->refunds->where('status', 'succeeded') as $refund) {
            $at($refund->created_at, $case->money((int) $refund->amount, $refund->currency).' refunded.');
        }

        if ($case->event?->cancelled_at !== null) {
            $at($case->event->cancelled_at, 'the organizer cancelled the night.');
        }

        usort($events, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $lines = ['Order '.$order->reference.' — '.$case->eventTitle()
            .($case->eventWhen() ? ', '.$case->eventWhen() : '')
            .($case->eventWhere() ? ', '.$case->eventWhere() : '').'.'];

        foreach ($events as [, $text]) {
            $lines[] = '- '.$text;
        }

        if ($kind === Reasons::FRAUD && $case->priorOrders->isNotEmpty()) {
            $used = $case->priorOrders->filter(fn ($prior) => CaseFile::wasUsed($prior))->count();
            $lines[] = 'The same email address has '.$case->priorOrders->count().' earlier paid order(s) on myFiesta, none of them disputed'
                .($used > 0 ? ", {$used} with tickets used at the door" : '').'.';
        }

        if (in_array($kind, [Reasons::REFUND, Reasons::GENERAL], true) && $case->refunded() === 0) {
            $lines[] = 'No refund was made through myFiesta for this order.';
        }

        return implode("\n", $lines);
    }

    // --- what was found ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $compelling
     * @return list<array{key: string, label: string, found: bool, detail: string|null}>
     */
    private function checklist(CaseFile $case, string $kind, ?array $compelling): array
    {
        $order = $case->order;
        $stripe = $case->gateway() === 'stripe';
        $items = [];
        $item = function (string $key, string $label, bool $found, ?string $detail = null) use (&$items) {
            $items[] = ['key' => $key, 'label' => $label, 'found' => $found, 'detail' => $detail];
        };

        $item('receipt', 'The order and its receipt', true, 'Order '.$order->reference.', '.$case->total());
        $item('terms', 'The buyer accepted the terms and the refund policy', $order->terms_accepted_at !== null,
            $order->terms_accepted_at ? 'Version '.$order->terms_version.', '.CaseFile::at($order->terms_accepted_at) : 'Not recorded on this order');
        $item('payment_record', $case->processorName().'\'s own record of the payment', $case->paymentFacts() !== [],
            match ($case->payment?->status) {
                null => 'Never asked for',
                'captured' => $case->cardLine(),
                'gave_up' => 'The processor never answered: '.Str::limit((string) $case->payment->last_error, 120),
                default => 'Still being fetched',
            });

        if ($kind === Reasons::FRAUD) {
            if ($stripe) {
                $item('three_d_secure', 'The card\'s bank authenticated the buyer (3D Secure)', $case->authenticated(), $case->threeDSecureLine() ?? 'No 3D Secure on this payment');
                $item('card_checks', 'The CVC check passed', $case->cvcPassed(), $case->checksLine());
                $item('risk', 'Stripe\'s fraud checks let it through as normal risk', ($case->charge()['outcome']['risk_level'] ?? null) === 'normal', $case->riskLine());
            }

            $item('purchase_ip', 'The internet address the order came from', $order->purchase_ip !== null, $order->purchase_ip);
            $item('prior_orders', 'Earlier undisputed orders from the same email address', $case->priorOrders->isNotEmpty(),
                $case->priorOrders->isEmpty() ? 'None' : $case->priorOrders->count().' earlier order(s)');
        }

        $item('issued', 'Tickets issued', $case->tickets->isNotEmpty(), $case->tickets->count().' ticket(s)');
        $item('emailed', 'Tickets emailed to the buyer', $case->emailsToBuyer()->isNotEmpty(),
            $case->emailsToBuyer()->isEmpty() ? 'No email to the buyer on record' : $case->emailsToBuyer()->count().' email(s), first at '.CaseFile::at($case->emailsToBuyer()->first()->occurred_at));
        $item('opened', 'The tickets were opened or shown', $case->openings()->isNotEmpty(),
            $case->openings()->isEmpty() ? 'Never opened on record' : $case->openings()->count().' time(s)');
        $item('scanned', 'Let in at the door', $case->admissions()->isNotEmpty(),
            $case->admissions()->isEmpty() ? 'No ticket scanned in' : $case->ticketsAdmitted().' of '.$case->tickets->count().' ticket(s)');

        if ($kind !== Reasons::FRAUD || $case->hasHappened()) {
            $item('completion', 'The night was recorded as having taken place', $case->completion !== null,
                $case->completionLine() ?? ($case->hasHappened() ? 'Not recorded yet' : 'The night has not happened yet'));
        }

        if (in_array($kind, [Reasons::REFUND, Reasons::GENERAL], true) || ! $stripe) {
            $item('policy', 'The refund policy as the buyer was shown it', $case->policy !== null,
                $case->policy !== null ? 'Version '.$order->terms_version : 'No copy kept for this order\'s version');
        }

        if ($kind === Reasons::DUPLICATE) {
            $item('other_order', 'The other charge is a separate order', $case->sameNight->isNotEmpty(),
                $case->sameNight->isEmpty() ? 'This is the buyer\'s only order for this night' : 'Order '.$case->sameNight->first()->reference);
        }

        if ($compelling !== null) {
            $item('compelling_evidence', 'Visa Compelling Evidence 3.0', (bool) $compelling['eligible'], $compelling['why']);
        }

        return $items;
    }

    /**
     * Anything that says the buyer may be right. Shown above the answer, so
     * nobody contests a dispute that should be accepted.
     *
     * @return list<string>
     */
    private function cautions(CaseFile $case, string $kind): array
    {
        $refunded = $case->refunded();
        $total = (int) $case->order->total_amount;

        return array_values(array_filter([
            $case->wasCancelled() && $refunded < $total
                ? 'The night was cancelled and the order was not refunded in full. The refund policy owes the buyer a refund — accepting is probably right.'
                : null,
            $refunded > 0
                ? $case->money($refunded).' of '.$case->total().' has already been refunded. Say so in the answer, or accept if the refund covers what is disputed.'
                : null,
            $case->tickets->isEmpty() ? 'No ticket was ever issued for this order.' : null,
            $kind === Reasons::NOT_RECEIVED && $case->emailsToBuyer()->isEmpty() && $case->openings()->isEmpty() && $case->admissions()->isEmpty()
                ? 'Nothing on record shows the buyer received or used the tickets.'
                : null,
            $kind === Reasons::FRAUD && ! $case->authenticated() && $case->openings()->isEmpty() && $case->admissions()->isEmpty()
                ? 'Nothing ties this payment to the cardholder: the bank did not authenticate it, and the tickets were never opened or used.'
                : null,
            $kind === Reasons::DUPLICATE && $case->sameNight->isEmpty()
                ? 'This is the buyer\'s only order for this night. If they were charged twice for it, the second charge is a mistake — accepting is right.'
                : null,
            $case->dispute->evidence_due_at?->isPast()
                ? 'The deadline for evidence has passed. The processor may no longer accept an answer.'
                : null,
        ]));
    }

    /**
     * Each field cut to what it may hold, at a line where it can be, with the
     * note that it was — the note included in what it may hold, or the page
     * would refuse to save a field staff never wrote and Stripe would refuse
     * to take it.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function fit(array $fields): array
    {
        foreach ($fields as $name => $value) {
            $max = self::FIELDS[$name]['max'] ?? 5000;

            if (mb_strlen($value) > $max) {
                $room = max(0, $max - mb_strlen(self::SHORTENED));
                $cut = mb_substr($value, 0, $room);
                $line = mb_strrpos($cut, "\n");
                $kept = $line !== false && $line > $room / 2 ? mb_substr($cut, 0, $line) : $cut;

                $fields[$name] = mb_substr($kept.self::SHORTENED, 0, $max);
            }
        }

        return $fields;
    }
}
