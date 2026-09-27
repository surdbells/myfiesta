<?php

namespace App\Services\Disputes;

use App\Models\Order;
use App\Models\PaymentEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Visa's Compelling Evidence 3.0, when our records can establish it.
 *
 * For a Visa fraud dispute, Visa decides for the merchant outright when the
 * same card paid the same merchant at least twice before, 120 to 365 days
 * earlier, without disputing it — and each of those earlier payments matches
 * the disputed one on at least two of: the internet address, the device, the
 * delivery address, the customer's account; one of the two being the address
 * or the device. Stripe says when a dispute could qualify, by listing
 * visa_compelling_evidence_3 among its enhanced_eligibility_types, and takes
 * the evidence in a shape of its own (enhanced_evidence).
 *
 * Of the four, our records could hold two: the address an order came from,
 * and the account it was bought on. No device is identified — there is no
 * device fingerprinting on this platform, on purpose — and nothing is
 * delivered to a street. So both must match.
 *
 * Today no order records an account: the checkout reads no sign-in, even from
 * a buyer who has one (CreateOrderRequest), so orders.user_id is empty on
 * every order it takes. That makes Compelling Evidence 3.0 unavailable for
 * now, and the page says so, plainly, rather than send something Visa will not
 * accept. The account a buyer is given for their tickets (TicketIssuer) is not
 * used in its place: it is made from the email address typed at checkout, and
 * calling that the account the order was placed on would claim a sign-in that
 * never happened. A checkout that does record the signed-in account makes
 * this work with no change here. The same card is known by Stripe's card
 * fingerprint on the processor's own record of each payment.
 *
 * Nothing is sent unless Stripe listed it and every condition is met here.
 */
final class CompellingEvidence
{
    public const TYPE = 'visa_compelling_evidence_3';

    /** How many earlier payments Visa asks for. */
    public const PRIOR_NEEDED = 2;

    /**
     * The assessment, or null when Stripe did not list the dispute as able to
     * qualify.
     *
     * @param  list<string>  $eligibility  Stripe's enhanced_eligibility_types
     * @return array{listed: true, eligible: bool, why: string, stripe_status: string|null, disputed: array<string, string>|null, prior: list<array<string, string>>}|null
     */
    public function assess(CaseFile $case, array $eligibility, ?string $stripeStatus = null): ?array
    {
        if (! in_array(self::TYPE, $eligibility, true)) {
            return null;
        }

        $order = $case->order;
        $fingerprint = $case->card()['fingerprint'] ?? null;
        $opened = CarbonImmutable::instance($case->dispute->opened_at ?? now());

        $missing = array_values(array_filter([
            $order->purchase_ip === null ? 'the internet address the order came from was not kept' : null,
            $order->user_id === null ? 'the order was not placed signed in to a myFiesta account — the checkout does not record one on any order today — so there is no account to match; with no device identified, the internet address alone is only one of the two details Visa asks to match' : null,
            ! is_string($fingerprint) || $fingerprint === '' ? 'Stripe\'s record of this payment was not kept, so the card cannot be matched to earlier payments' : null,
        ]));

        $prior = $missing === [] ? $this->priorPayments($order, (string) $fingerprint, $opened) : collect();

        if ($missing === [] && $prior->count() < self::PRIOR_NEEDED) {
            $missing[] = 'Visa asks for at least '.self::PRIOR_NEEDED.' earlier undisputed payments on the same card, made 120 to 365 days before the dispute from the same account and the same internet address; our records have '.$prior->count();
        }

        $eligible = $missing === [];

        return [
            'listed' => true,
            'eligible' => $eligible,
            'why' => $eligible
                ? 'Stripe says this dispute could qualify, and our records establish it: '.$prior->count().' earlier undisputed payments on the same card, from the same account and internet address, 120 to 365 days before the dispute.'
                : 'Stripe says this dispute could qualify for Visa\'s Compelling Evidence 3.0, but our records cannot establish it: '.implode('; ', $missing).'. It is answered with the ordinary evidence instead.',
            'stripe_status' => $stripeStatus,
            'disputed' => $eligible ? [
                'customer_account_id' => (string) $order->user_id,
                'customer_email_address' => (string) $order->buyer_email,
                'customer_purchase_ip' => (string) $order->purchase_ip,
                'merchandise_or_services' => 'services',
                'product_description' => $this->describe($order),
            ] : null,
            'prior' => $eligible ? $prior->map(fn (array $payment) => [
                'charge' => $payment['charge'],
                'customer_account_id' => (string) $payment['order']->user_id,
                'customer_email_address' => (string) $payment['order']->buyer_email,
                'customer_purchase_ip' => (string) $payment['order']->purchase_ip,
                'product_description' => $this->describe($payment['order']),
                // For the page; not sent.
                'reference' => $payment['order']->reference,
                'paid_at' => CaseFile::at($payment['order']->paid_at),
            ])->values()->all() : [],
        ];
    }

    /**
     * What Stripe takes as evidence[enhanced_evidence], from an assessment
     * that established it; nothing otherwise.
     *
     * @param  array<string, mixed>|null  $assessment
     * @return array<string, mixed>
     */
    public static function enhanced(?array $assessment): array
    {
        if (! ($assessment['eligible'] ?? false) || ! is_array($assessment['disputed'] ?? null)) {
            return [];
        }

        return [self::TYPE => [
            'disputed_transaction' => $assessment['disputed'],
            'prior_undisputed_transactions' => array_map(
                fn (array $payment) => array_intersect_key($payment, array_flip([
                    'charge', 'customer_account_id', 'customer_email_address', 'customer_purchase_ip', 'product_description',
                ])),
                $assessment['prior'] ?? [],
            ),
        ]];
    }

    /**
     * Earlier payments that qualify: same account, same card, same address,
     * paid 120 to 365 days before the dispute, through Stripe, never disputed.
     *
     * @return Collection<int, array{order: Order, charge: non-empty-string}>
     */
    private function priorPayments(Order $order, string $fingerprint, CarbonImmutable $opened): Collection
    {
        $candidates = Order::query()
            ->with('event:id,title')
            ->where('user_id', $order->user_id)
            ->where('purchase_ip', $order->purchase_ip)
            ->where('gateway', 'stripe')
            ->whereKeyNot($order->id)
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->whereNull('disputed_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('disputes')->whereColumn('disputes.order_id', 'orders.id'))
            ->whereBetween('paid_at', [$opened->subDays(365), $opened->subDays(120)])
            ->orderByDesc('paid_at')
            ->limit(50)
            ->get();

        $records = PaymentEvidence::query()
            ->where('status', PaymentEvidence::CAPTURED)
            ->whereIn('order_id', $candidates->modelKeys())
            ->get()
            ->keyBy('order_id');

        return $candidates
            ->map(function (Order $candidate) use ($records, $fingerprint) {
                $facts = (array) ($records[$candidate->id]->facts ?? []);
                $charge = $facts['charge']['id'] ?? null;

                return ($facts['charge']['card']['fingerprint'] ?? null) === $fingerprint && is_string($charge) && $charge !== ''
                    ? ['order' => $candidate, 'charge' => $charge]
                    : null;
            })
            ->filter()
            ->values();
    }

    private function describe(Order $order): string
    {
        return 'Event tickets: '.($order->event->title ?? 'an event').' (order '.$order->reference.')';
    }
}
