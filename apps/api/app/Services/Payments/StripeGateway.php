<?php

namespace App\Services\Payments;

use App\Contracts\Payments\AnswersDisputes;
use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\DescribesPayments;
use App\Contracts\Payments\EvidencePackage;
use App\Contracts\Payments\FindsCheckouts;
use App\Contracts\Payments\FindsRefunds;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentRecord;
use App\Contracts\Payments\ProcessorDispute;
use App\Contracts\Payments\RefundNotice;
use App\Contracts\Payments\RefundResult;
use App\Contracts\Payments\TotalsRefunds;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\Disputes\RefundPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Stripe, for every currency except naira.
 *
 * Talks to Stripe's REST API directly rather than through Cashier. Cashier is
 * built around subscriptions and a billable model; a ticket sale is a one-shot
 * charge against an order that may have no account behind it at all, since
 * guest checkout is the primary path. Using it here would mean adopting its
 * shape without its benefits.
 */
class StripeGateway implements AnswersDisputes, DescribesPayments, FindsCheckouts, FindsRefunds, PaymentGateway, TotalsRefunds
{
    /**
     * How long Stripe's payment page stays open.
     *
     * Thirty minutes, Stripe's minimum, instead of its default day: a page
     * left open overnight could otherwise take money for tickets released
     * hours ago. The stock hold is set from this (CheckoutService::HOLD_MINUTES)
     * so that it always outlives the page.
     */
    public const SESSION_MINUTES = 30;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        // Naira goes to Paystack: card penetration in Nigeria is low, and
        // Paystack carries bank transfer, USSD, and mobile money.
        return strtoupper($currency) !== 'NGN';
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        // The amount comes from the order, which computed it from prices held
        // in the database. There is no parameter for a caller to influence it.
        $response = Http::withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $options->idempotencyKey])
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'customer_email' => $order->buyer_email,
                'success_url' => $options->successUrl,
                'cancel_url' => $options->cancelUrl,
                'client_reference_id' => $order->id,
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->getTimestamp(),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $order->total_amount,
                        'product_data' => ['name' => $order->event->title],
                    ],
                ]],
                'metadata' => ['order_id' => $order->id, 'reference' => $order->reference]
                    + $options->metadata,
            ] + $this->answeringDisputes($order));

        $response->throw();

        return new CheckoutSession(
            reference: $response->json('id'),
            redirectUrl: $response->json('url'),
            expiresAt: $response->json('expires_at')
                ? (new \DateTimeImmutable)->setTimestamp($response->json('expires_at'))
                : null,
        );
    }

    /**
     * What the payment page says and asks so that a dispute later has
     * something to be answered with.
     *
     * The night's name on the bank statement, so the charge is recognised
     * (StatementDescriptor). The refund policy in one sentence by the pay
     * button — the same sentence as beside the terms box on our own
     * checkout, from the copy kept for the terms in force (RefundPolicy) —
     * because a dispute is judged on what the buyer was shown. The card's
     * bank asked to check it is the cardholder as configured: a payment
     * that passed that check is one the bank, not the organizer, answers
     * for as fraud. And, once the terms page is set in Stripe's dashboard,
     * Stripe's own terms box, so Stripe keeps its own record of the
     * agreement beside ours.
     *
     * @return array<string, mixed>
     */
    private function answeringDisputes(Order $order): array
    {
        $suffix = StatementDescriptor::suffix(
            (string) $order->event->title,
            config('payments.stripe.statement_descriptor_prefix'),
        );

        $threeDSecure = config('payments.stripe.request_three_d_secure');
        $consent = (bool) config('payments.stripe.collect_terms_consent');
        $site = rtrim((string) config('app.public_url'), '/');

        // Left off rather than refusing the sale when the version in force
        // has no summary; LegalCopiesTest is what stops that reaching a deploy.
        $summary = app(RefundPolicy::class)->summary();

        $options = [
            'payment_method_options' => ['card' => [
                // Anything else is refused by Stripe, and with it the whole
                // checkout; a mistyped setting falls back to Stripe's own
                // judgement rather than to no sale.
                'request_three_d_secure' => in_array($threeDSecure, ['automatic', 'any', 'challenge'], true)
                    ? $threeDSecure
                    : 'automatic',
            ]],
            'custom_text' => array_filter([
                'submit' => $summary === null ? null : ['message' => $summary],
                'terms_of_service_acceptance' => $consent
                    ? ['message' => "I accept the [terms]({$site}/terms), the [privacy policy]({$site}/privacy) and the [refund policy]({$site}/refunds)."]
                    : null,
            ]),
        ];

        if ($suffix !== null) {
            $options['payment_intent_data'] = ['statement_descriptor_suffix' => $suffix];
        }

        if ($consent) {
            $options['consent_collection'] = ['terms_of_service' => 'required'];
        }

        return $options;
    }

    /**
     * Stripe's record of an order's payment: the payment intent and its most
     * recent charge, reduced to what answers a dispute.
     *
     * Kept, with the reason for each:
     *   the charge's id, amount and time        which payment this was
     *   3D Secure's result, version and flow    whether the card's bank checked
     *                                           it was the cardholder; with a
     *                                           result of authenticated, fraud
     *                                           is the bank's to answer for
     *   the network's status and Radar's        what the card network and
     *   outcome, level and score                Stripe's fraud checks said
     *   the CVC, postcode and street checks     whether what was typed matched
     *                                           what the bank holds
     *   brand, last four, fingerprint, wallet   which card, without its number;
     *                                           the fingerprint is how Stripe
     *                                           says two payments were one card
     *   the statement line                      what the buyer's bank showed
     *   the receipt address                     where Stripe sent its receipt
     *
     * Nothing else — not the billing name or address, not the card's expiry
     * or country — and never a card number, which Stripe does not give out.
     *
     * Read with whatever API version the account is on: the charge is
     * `latest_charge` on current versions and the first of `charges` on older
     * ones, and both are handled rather than one being pinned.
     */
    public function describePayment(Order $order): PaymentRecord
    {
        $intentId = $order->gateway_payment_reference ?? $this->intentOf($order);

        if ($intentId === null) {
            throw new RuntimeException('Stripe has no payment for this checkout yet.');
        }

        $intent = $this->fetch('payment_intents/'.rawurlencode($intentId));
        $charge = $intent['latest_charge'] ?? null;

        if (is_string($charge) && $charge !== '') {
            $charge = $this->fetch('charges/'.rawurlencode($charge));
        } elseif (! is_array($charge)) {
            $charge = $intent['charges']['data'][0] ?? null;
        }

        if (! is_array($charge)) {
            throw new RuntimeException('Stripe has no charge on this payment yet.');
        }

        $details = $charge['payment_method_details'] ?? [];
        $card = is_array($details['card'] ?? null) ? $details['card'] : null;
        $secure = is_array($card['three_d_secure'] ?? null) ? $card['three_d_secure'] : null;
        $outcome = is_array($charge['outcome'] ?? null) ? $charge['outcome'] : [];

        return new PaymentRecord(
            reference: $intentId,
            facts: [
                'processor' => 'stripe',
                'livemode' => (bool) ($intent['livemode'] ?? $charge['livemode'] ?? false),
                'payment_intent' => [
                    'id' => $intent['id'] ?? $intentId,
                    'status' => $intent['status'] ?? null,
                    'amount' => isset($intent['amount']) ? (int) $intent['amount'] : null,
                    'currency' => isset($intent['currency']) ? strtoupper((string) $intent['currency']) : null,
                    'created' => $this->time($intent['created'] ?? null),
                ],
                'charge' => [
                    'id' => $charge['id'] ?? null,
                    'status' => $charge['status'] ?? null,
                    'paid' => $charge['paid'] ?? null,
                    'captured' => $charge['captured'] ?? null,
                    'amount' => isset($charge['amount']) ? (int) $charge['amount'] : null,
                    'currency' => isset($charge['currency']) ? strtoupper((string) $charge['currency']) : null,
                    'created' => $this->time($charge['created'] ?? null),
                    'statement_descriptor' => $charge['calculated_statement_descriptor'] ?? null,
                    'payment_method_type' => $details['type'] ?? null,
                    'outcome' => [
                        'network_status' => $outcome['network_status'] ?? null,
                        'type' => $outcome['type'] ?? null,
                        'reason' => $outcome['reason'] ?? null,
                        'risk_level' => $outcome['risk_level'] ?? null,
                        // Only on accounts with Radar for Fraud Teams.
                        'risk_score' => $outcome['risk_score'] ?? null,
                    ],
                    'card' => $card === null ? null : [
                        'brand' => $card['brand'] ?? null,
                        'network' => $card['network'] ?? null,
                        'last4' => $card['last4'] ?? null,
                        'fingerprint' => $card['fingerprint'] ?? null,
                        'wallet' => $card['wallet']['type'] ?? null,
                        'checks' => [
                            'cvc_check' => $card['checks']['cvc_check'] ?? null,
                            'address_postal_code_check' => $card['checks']['address_postal_code_check'] ?? null,
                            'address_line1_check' => $card['checks']['address_line1_check'] ?? null,
                        ],
                        'three_d_secure' => $secure === null ? null : array_filter([
                            'result' => $secure['result'] ?? null,
                            'result_reason' => $secure['result_reason'] ?? null,
                            // Older API versions say it as a yes or no.
                            'authenticated' => $secure['authenticated'] ?? null,
                            'version' => $secure['version'] ?? null,
                            'authentication_flow' => $secure['authentication_flow'] ?? null,
                            // The card network's own word on how far the
                            // check went: 05 or 02 fully, 06 or 01 attempted.
                            'electronic_commerce_indicator' => $secure['electronic_commerce_indicator'] ?? null,
                            'exemption_indicator' => $secure['exemption_indicator'] ?? null,
                            // Not a field Stripe documents today; kept if it
                            // ever says so in as many words.
                            'liability_shift' => $secure['liability_shift'] ?? null,
                        ], fn ($value) => $value !== null),
                    ],
                ],
            ],
            receiptEmail: is_string($charge['receipt_email'] ?? null) ? $charge['receipt_email'] : null,
        );
    }

    /**
     * The dispute as Stripe has it: its reason, the network's code, when the
     * evidence is due, how many times it has been answered, and whether Stripe
     * says it could be answered with more (enhanced_eligibility_types — Visa's
     * Compelling Evidence 3.0 among them).
     */
    public function describeDispute(Dispute $dispute): ProcessorDispute
    {
        return $this->disputeFrom($this->fetch('disputes/'.rawurlencode($dispute->gateway_reference)));
    }

    /**
     * Upload each document to Stripe's Files API, then send every field with
     * submit=true, which is Stripe's "this is our answer, decide it".
     *
     * Only at submission: nothing is handed to Stripe while staff are still
     * reading the draft. Each file is remembered as soon as Stripe has it, and
     * the fields go under a key made from what they say, so a second try after
     * a timeout is answered with the first try's answer and uploads nothing
     * twice. A try Stripe refused spends its keys (EvidencePackage::spent), so
     * pressing again is asked afresh rather than told the refusal again.
     */
    public function submitDisputeEvidence(Dispute $dispute, EvidencePackage $package): ProcessorDispute
    {
        $evidence = $package->fields;

        foreach ($package->files as $kind) {
            $evidence[$kind] = $package->uploaded($kind) ?? $this->uploadEvidence($package, $kind);
        }

        if ($package->enhanced !== []) {
            $evidence['enhanced_evidence'] = $package->enhanced;
        }

        // Stripe reads a boolean from the words true and false.
        $body = ['evidence' => $evidence, 'submit' => 'true'];

        return $this->disputeFrom($this->answer($package, 'disputes/'.rawurlencode($dispute->gateway_reference), $body, $package->key('submit', $body)));
    }

    /**
     * Close the dispute, which is Stripe's word for conceding it: the buyer
     * keeps the money, and charge.dispute.closed follows as a loss, which is
     * what takes it off the organizer's balance (DisputeService).
     */
    public function acceptDispute(Dispute $dispute, EvidencePackage $package): ProcessorDispute
    {
        return $this->disputeFrom($this->answer($package, 'disputes/'.rawurlencode($dispute->gateway_reference).'/close', [], $package->key('close')));
    }

    /**
     * Stripe's dispute, reduced to what answers it.
     *
     * The network's reason code is on the dispute on current API versions and
     * on its card details on others; either is read. Nothing about the card
     * beyond its brand is kept here — PaymentRecord already has the rest.
     *
     * @param  array<string, mixed>  $dispute
     */
    public function disputeFrom(array $dispute): ProcessorDispute
    {
        $details = is_array($dispute['evidence_details'] ?? null) ? $dispute['evidence_details'] : [];
        $card = is_array($dispute['payment_method_details']['card'] ?? null) ? $dispute['payment_method_details']['card'] : [];
        $charge = $dispute['charge'] ?? null;
        $network = $dispute['network_reason_code'] ?? $card['network_reason_code'] ?? null;
        $enhanced = array_values(array_filter((array) ($dispute['enhanced_eligibility_types'] ?? []), 'is_string'));
        $due = $details['due_by'] ?? null;

        return new ProcessorDispute(
            reference: (string) ($dispute['id'] ?? ''),
            status: isset($dispute['status']) ? (string) $dispute['status'] : null,
            reason: isset($dispute['reason']) ? (string) $dispute['reason'] : null,
            networkReasonCode: is_scalar($network) && $network !== '' ? (string) $network : null,
            amount: isset($dispute['amount']) ? (int) $dispute['amount'] : null,
            currency: isset($dispute['currency']) ? strtoupper((string) $dispute['currency']) : null,
            dueAt: is_numeric($due) ? CarbonImmutable::createFromTimestamp((int) $due, 'UTC') : null,
            facts: [
                'processor' => 'stripe',
                'id' => $dispute['id'] ?? null,
                'charge' => is_array($charge) ? ($charge['id'] ?? null) : $charge,
                'payment_intent' => is_array($dispute['payment_intent'] ?? null) ? ($dispute['payment_intent']['id'] ?? null) : ($dispute['payment_intent'] ?? null),
                'status' => $dispute['status'] ?? null,
                'reason' => $dispute['reason'] ?? null,
                'network_reason_code' => is_scalar($network) ? $network : null,
                'amount' => isset($dispute['amount']) ? (int) $dispute['amount'] : null,
                'currency' => isset($dispute['currency']) ? strtoupper((string) $dispute['currency']) : null,
                'created' => $this->time($dispute['created'] ?? null),
                'livemode' => (bool) ($dispute['livemode'] ?? false),
                'is_charge_refundable' => $dispute['is_charge_refundable'] ?? null,
                'evidence_details' => [
                    'due_by' => $this->time($due),
                    'has_evidence' => $details['has_evidence'] ?? null,
                    'past_due' => $details['past_due'] ?? null,
                    'submission_count' => isset($details['submission_count']) ? (int) $details['submission_count'] : null,
                    'enhanced_eligibility' => is_array($details['enhanced_eligibility'] ?? null) ? $details['enhanced_eligibility'] : null,
                ],
                'enhanced_eligibility_types' => $enhanced,
                'card' => ['brand' => $card['brand'] ?? null, 'case_type' => $card['case_type'] ?? null],
            ],
            enhancedEligibility: $enhanced,
        );
    }

    /** One document to Stripe's Files API, marked as evidence for a dispute. */
    private function uploadEvidence(EvidencePackage $package, string $kind): string
    {
        $file = $package->file($kind);

        $response = Http::withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $package->key('file-'.$kind, ['sha256' => $file->sha256()])])
            ->timeout(60)
            ->attach('file', $file->bytes, $file->name, ['Content-Type' => $file->mimeType])
            ->post('https://files.stripe.com/v1/files', ['purpose' => 'dispute_evidence']);

        $id = $response->json('id');

        if ($response->failed() || ! is_string($id) || $id === '') {
            $package->spent();

            throw new RuntimeException("Stripe would not take {$file->name}: ".($response->json('error.message') ?? 'it answered '.$response->status().'.'));
        }

        $package->remember($kind, $id, $file);

        return $id;
    }

    /**
     * A request that answers a dispute, under its idempotency key.
     *
     * A refusal spends the key; no answer at all (a timeout, a dropped
     * connection) leaves it, so the next try learns what the first did.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function answer(EvidencePackage $package, string $path, array $body, string $key): array
    {
        $response = Http::withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $key])
            ->timeout(30)
            ->asForm()
            ->post('https://api.stripe.com/v1/'.$path, $body);

        if ($response->failed()) {
            $package->spent();

            throw new RuntimeException('Stripe said: '.($response->json('error.message') ?? 'it answered '.$response->status().'.'));
        }

        return (array) $response->json();
    }

    /** The payment made on the order's checkout, when the order was never told it. */
    private function intentOf(Order $order): ?string
    {
        if ($order->gateway_reference === null) {
            return null;
        }

        $intent = $this->fetch('checkout/sessions/'.rawurlencode($order->gateway_reference))['payment_intent'] ?? null;

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    /** @return array<string, mixed> */
    private function fetch(string $path): array
    {
        return (array) Http::withToken($this->secretKey)
            ->timeout(15)
            ->get('https://api.stripe.com/v1/'.$path)
            ->throw()
            ->json();
    }

    private function time(mixed $timestamp): ?string
    {
        return is_numeric($timestamp)
            ? CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC')->toIso8601String()
            : null;
    }

    /**
     * Stripe signs with HMAC-SHA256 over "timestamp.payload".
     *
     * The timestamp is part of the signed material specifically so a captured
     * request cannot be replayed later, so it is checked as well as the digest.
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        /*
         * No secret, no webhook — in every environment.
         *
         * An HMAC keyed with an empty string is a digest anybody can compute,
         * so a blank STRIPE_WEBHOOK_SECRET would turn "signed by Stripe" into
         * "signed by whoever read this file", and any of them could mark an
         * order paid. Refusing everything is the only safe reading of a
         * missing key; the start-up check (Preflight) is what stops that
         * reaching production at all.
         */
        if ($this->webhookSecret === '') {
            Log::warning('Stripe webhook rejected: STRIPE_WEBHOOK_SECRET is not set.');

            return false;
        }

        $header = $this->header($headers, 'stripe-signature');

        if ($header === null) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('Stripe webhook rejected: timestamp outside tolerance.');

            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        foreach ($signatures as $signature) {
            // Constant-time: a fast string compare leaks how much of the digest
            // matched, one byte at a time.
            if (hash_equals($expected, (string) $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        if (! $this->verifySignature($payload, $headers)) {
            return null;
        }

        $event = json_decode($payload, true);
        $object = $event['data']['object'] ?? [];
        $name = $event['type'] ?? '';

        $type = match ($name) {
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid'
                ? PaymentEvent::PAID
                : null,
            'checkout.session.expired' => PaymentEvent::EXPIRED,
            'charge.refunded',
            'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated' => PaymentEvent::REFUNDED,
            'charge.dispute.created' => PaymentEvent::DISPUTED,
            // 'warning_closed' is a card network dropping an enquiry before it
            // became a dispute, which is the organizer keeping the money.
            'charge.dispute.closed' => match ($object['status'] ?? '') {
                'lost' => PaymentEvent::DISPUTE_LOST,
                'won', 'warning_closed' => PaymentEvent::DISPUTE_WON,
                default => null,
            },
            'payment_intent.payment_failed' => PaymentEvent::FAILED,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $refund = $type === PaymentEvent::REFUNDED ? $this->refundNotice($name, $object) : null;

        if ($type === PaymentEvent::REFUNDED && $refund === null) {
            return null;
        }

        // A dispute's object is the dispute, and a refund's is the refund or
        // the charge; each names the payment it is about, which is what the
        // order kept when it was paid. Everything else is the thing itself.
        $reference = match (true) {
            $type === PaymentEvent::REFUNDED => $object['payment_intent'] ?? '',
            str_starts_with($type, 'dispute') || $type === PaymentEvent::DISPUTED => $object['payment_intent']
                ?? $object['charge']
                ?? $object['id']
                ?? '',
            default => $object['id'] ?? '',
        };

        return new PaymentEvent(
            type: $type,
            reference: (string) $reference,
            amountMinorUnits: (int) ($object['amount_total'] ?? $object['amount_refunded'] ?? $object['amount'] ?? 0),
            currency: strtoupper($object['currency'] ?? ''),
            eventId: $event['id'] ?? '',
            raw: $event,
            paymentReference: $object['payment_intent'] ?? null,
            refund: $refund,
        );
    }

    /**
     * What a refund event says, or null when there is nothing in it for us.
     *
     * charge.refunded is the one that finds refunds made in Stripe's own
     * dashboard. It carries the running total for the payment, and a running
     * total cannot be counted twice: read again, or out of order, it still
     * says the same thing about how much has gone back.
     *
     * The events about a single refund are only listened to when the refund
     * is one of ours, tagged on the way out (refund() below). They settle a
     * refund of ours whose answer never came back, and they are how Stripe
     * says one failed after the fact. One made elsewhere is left to the
     * running total; counting it here as well would count it twice.
     */
    private function refundNotice(string $name, array $object): ?RefundNotice
    {
        if ($name === 'charge.refunded') {
            return new RefundNotice(
                status: RefundNotice::SUCCEEDED,
                totalRefundedMinorUnits: (int) ($object['amount_refunded'] ?? 0),
                // Only on older API versions, which list the refunds on the
                // charge. When they are there, they say which were ours.
                parts: array_values(array_map(
                    fn (array $refund) => $this->oneRefund($refund),
                    array_filter($object['refunds']['data'] ?? [], 'is_array'),
                )),
            );
        }

        $notice = $this->oneRefund($object);

        return $notice->ourReference !== null ? $notice : null;
    }

    private function oneRefund(array $refund): RefundNotice
    {
        return new RefundNotice(
            // Pending is a refund Stripe has accepted and is still paying out,
            // which is a yes. requires_action is Stripe waiting on somebody.
            status: match ($refund['status'] ?? 'succeeded') {
                'failed', 'canceled' => RefundNotice::FAILED,
                'requires_action' => RefundNotice::PENDING,
                default => RefundNotice::SUCCEEDED,
            },
            amountMinorUnits: (int) ($refund['amount'] ?? 0),
            processorReference: $refund['id'] ?? null,
            ourReference: $refund['metadata']['refund_id'] ?? null,
        );
    }

    /**
     * Ask Stripe for money back, once, however many times this is called.
     *
     * The key is our Refund row's id, sent as Stripe's Idempotency-Key: a
     * second request with the same key gets the first one's answer instead of
     * a second refund. That is what makes it safe to try again when a request
     * times out — here, straight away and a couple of times, and later from
     * refunds:follow-up. Without a key there is no retry at all, because a
     * second request could be a second refund.
     *
     * The key goes in the metadata as well. It is how a refund event, or a
     * look through the payment's refunds (findRefund), says which of ours a
     * refund was.
     */
    public function refund(
        Order $order,
        int $amountMinorUnits,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): RefundResult {
        $metadata = array_filter(['refund_id' => $idempotencyKey, 'reason' => $reason], filled(...));

        try {
            $response = Http::withToken($this->secretKey)
                ->withHeaders($idempotencyKey === null ? [] : ['Idempotency-Key' => 'refund-'.$idempotencyKey])
                ->retry(
                    [250, 1000],
                    when: fn (Throwable $e) => $idempotencyKey !== null && $this->worthAnotherTry($e),
                    throw: false,
                )
                ->asForm()
                ->post('https://api.stripe.com/v1/refunds', array_filter([
                    // The payment, not the checkout session the order was made
                    // against: Stripe refuses a session id here.
                    'payment_intent' => $order->gateway_payment_reference ?? $order->gateway_reference,
                    'amount' => $amountMinorUnits,
                    // Not Stripe's `reason`, which takes one of three words —
                    // duplicate, fraudulent, requested_by_customer — and refuses
                    // the whole refund over anything else. Every reason here is a
                    // sentence somebody wrote, and most are not a customer's
                    // request, so none of the three is true of them. Kept as
                    // metadata instead, where Stripe shows it beside the refund
                    // and checks nothing.
                    'metadata' => $metadata ?: null,
                ]));
        } catch (ConnectionException $e) {
            return RefundResult::unknown('Stripe could not be reached: '.$e->getMessage());
        }

        // Stripe's own error, or another request with this key still under
        // way. Either way the refund may exist, and a "no" here would be a
        // guess.
        if ($response->serverError() || $response->status() === 409) {
            return RefundResult::unknown($response->json('error.message') ?? 'Stripe answered with an error ('.$response->status().').');
        }

        if ($response->failed()) {
            return RefundResult::failed($response->json('error.message', 'Refund refused by Stripe.'));
        }

        // Accepted, and already known not to have paid out. Rare, but a
        // refund Stripe calls failed has moved nothing, whatever the status
        // code of the answer.
        if (in_array($response->json('status'), ['failed', 'canceled'], true)) {
            return RefundResult::failed($response->json('failure_reason') ?? 'Stripe could not pay the refund out.');
        }

        return new RefundResult(
            succeeded: true,
            reference: (string) $response->json('id'),
            amountMinorUnits: (int) $response->json('amount'),
            currency: strtoupper((string) $response->json('currency')),
        );
    }

    /**
     * The refund tagged with this key, among the payment's refunds.
     *
     * Asked before a refund that got no answer is sent again. With the key
     * that is belt and braces — Stripe would answer the repeat with the first
     * refund — but Stripe forgets keys after a day, and the tag in the
     * metadata it keeps for good.
     */
    public function findRefund(
        Order $order,
        string $idempotencyKey,
        int $amountMinorUnits,
        DateTimeInterface $since,
        array $alreadyKnown = [],
    ): ?RefundResult {
        foreach ($this->refundsOn($order) as $refund) {
            if (($refund['metadata']['refund_id'] ?? null) !== $idempotencyKey) {
                continue;
            }

            return in_array($refund['status'] ?? '', ['failed', 'canceled'], true)
                ? RefundResult::failed($refund['failure_reason'] ?? 'Stripe could not pay the refund out.')
                : new RefundResult(
                    succeeded: true,
                    reference: (string) $refund['id'],
                    amountMinorUnits: (int) ($refund['amount'] ?? $amountMinorUnits),
                    currency: strtoupper((string) ($refund['currency'] ?? $order->currency)),
                );
        }

        return null;
    }

    /** The checkout session a payment intent was made on. */
    public function checkoutFor(string $paymentReference): ?string
    {
        $response = Http::withToken($this->secretKey)
            ->get('https://api.stripe.com/v1/checkout/sessions', [
                'payment_intent' => $paymentReference,
                'limit' => 1,
            ])
            ->throw();

        $id = $response->json('data.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * What charge.refunded would say now, asked for rather than announced.
     *
     * Counted from the refunds themselves. One Stripe has accepted and is
     * still paying out counts, as it does in the charge's own total; one that
     * failed, was cancelled, or is waiting on somebody has moved nothing.
     */
    public function refundedSoFar(Order $order): RefundNotice
    {
        $parts = array_map(fn (array $refund) => $this->oneRefund($refund), $this->refundsOn($order));

        return new RefundNotice(
            status: RefundNotice::SUCCEEDED,
            totalRefundedMinorUnits: array_sum(array_map(
                fn (RefundNotice $part) => $part->status === RefundNotice::SUCCEEDED ? (int) $part->amountMinorUnits : 0,
                $parts,
            )),
            parts: $parts,
        );
    }

    /**
     * Every refund on the order's payment, ours or not.
     *
     * A hundred is Stripe's most in one page, and more than any ticket order
     * has ever been refunded in.
     *
     * @return list<array<string, mixed>>
     */
    private function refundsOn(Order $order): array
    {
        $response = Http::withToken($this->secretKey)
            ->get('https://api.stripe.com/v1/refunds', [
                'payment_intent' => $order->gateway_payment_reference ?? $order->gateway_reference,
                'limit' => 100,
            ])
            ->throw();

        return array_values(array_filter($response->json('data') ?? [], 'is_array'));
    }

    /**
     * Whether a failed request is worth sending again with the same key.
     *
     * No connection, a timeout, Stripe's own error, or being asked to slow
     * down. A refusal — no balance, an amount too large — would only be
     * refused again.
     */
    private function worthAnotherTry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->serverError() || in_array($e->response->status(), [409, 429], true));
    }

    /** Header casing varies by server, so lookup is case-insensitive. */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }
}
