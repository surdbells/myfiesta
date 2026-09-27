<?php

namespace Tests\Concerns;

use App\Enums\PlatformRole;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A dispute on a real sale, as Stripe and Paystack open one: the sale bought
 * through checkout and paid by a signed notice (SellsTicketsForDisputes), the
 * processor's record of the payment collected, then the dispute notice — with
 * the processor's dispute API answering from Http::fake, in the shapes its
 * documentation gives.
 *
 * Uses SellsTicketsForDisputes, which a test class must use as well.
 */
trait OpensDisputes
{
    protected const BUYER_ADDRESS = '198.51.100.23';

    protected const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    /** @var array<string, array<string, mixed>> Stripe's disputes, by id, as its API returns them now */
    protected array $stripeDisputes = [];

    /** @var array<string, array<string, mixed>> Paystack's disputes, by id */
    protected array $paystackDisputes = [];

    /** How the next evidence submission to Stripe is answered: a status, or 0 for no answer at all. */
    protected int $stripeAnswersWith = 200;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> what Stripe answered each idempotency key, which it gives back to that key */
    protected array $stripeKeptAnswers = [];

    /** How many pieces of evidence Paystack has been given; each gets its own id. */
    protected int $paystackEvidenceGiven = 0;

    protected function staffMember(PlatformRole $role, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'@myfiesta.test',
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Bought from the buyer's own phone, paid through Stripe, and Stripe's
     * record of the payment collected.
     *
     * @param  array<string, mixed>  $session  anything else Stripe's session says (its terms box)
     * @return array{0: Order, 1: string, 2: string} the order, the payment intent, the charge
     */
    protected function paidOnStripe(?Event $event = null, ?TicketType $type = null, array $session = []): array
    {
        if ($event === null || $type === null) {
            [$event, $type] = $this->night();
        }

        $order = $this->buy($event, $type, ['REMOTE_ADDR' => self::BUYER_ADDRESS], ['User-Agent' => self::BROWSER]);

        $pi = 'pi_'.Str::random(24);
        $ch = 'ch_'.Str::random(24);
        $total = $order->total_amount;

        $this->processor['api.stripe.com/v1/payment_intents/'.$pi] = fn () => Http::response($this->stripeIntent($pi, $ch, $total));
        $this->processor['api.stripe.com/v1/charges/'.$ch] = fn () => Http::response($this->stripeCharge($ch, $pi, $total));

        $this->stripePaid($order, $pi, $session)->assertOk();
        $this->artisan('disputes:collect-evidence')->assertSuccessful();

        return [$order->refresh(), $pi, $ch];
    }

    /**
     * A Dispute as Stripe's API reference shows one.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function stripeDispute(Order $order, string $charge, string $reason, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'dp_'.Str::random(24),
            'object' => 'dispute',
            'amount' => $order->total_amount,
            'balance_transactions' => [],
            'charge' => $charge,
            'created' => now()->getTimestamp(),
            'currency' => strtolower($order->currency),
            'enhanced_eligibility_types' => [],
            'evidence' => ['receipt' => null, 'uncategorized_text' => null, 'product_description' => null],
            'evidence_details' => [
                'due_by' => now()->addDays(10)->getTimestamp(),
                'enhanced_eligibility' => [],
                'has_evidence' => false,
                'past_due' => false,
                'submission_count' => 0,
            ],
            'is_charge_refundable' => false,
            'livemode' => false,
            'metadata' => [],
            'network_reason_code' => $reason === 'fraudulent' ? '10.4' : '13.1',
            'payment_intent' => $order->gateway_payment_reference,
            'payment_method_details' => [
                'card' => ['brand' => 'visa', 'case_type' => 'chargeback', 'network_reason_code' => $reason === 'fraudulent' ? '10.4' : '13.1'],
                'type' => 'card',
            ],
            'reason' => $reason,
            'status' => 'needs_response',
        ], $overrides);
    }

    /**
     * Stripe's dispute API, answering from $stripeDisputes: the dispute when
     * asked, the evidence when sent, the dispute closed when conceded, and the
     * Files API taking each document.
     *
     * As Stripe does, an answer given to an idempotency key — a failure too —
     * is given back to that key whenever it is sent again. A request that got
     * no answer ($stripeAnswersWith = 0) left nothing kept.
     */
    protected function stripeAnswersDisputes(): void
    {
        $this->processor['files.stripe.com/v1/files'] = fn (ClientRequest $request) => $this->stripeKeeps($request, fn () => [[
            'id' => 'file_'.Str::random(24),
            'object' => 'file',
            'purpose' => 'dispute_evidence',
            'size' => 1024,
            'type' => 'pdf',
        ], 200]);

        $this->processor['api.stripe.com/v1/disputes/*'] = function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $id = explode('/', trim(Str::after($path, '/v1/disputes/'), '/'))[0];
            $dispute = $this->stripeDisputes[$id] ?? null;

            if ($dispute === null) {
                return Http::response(['error' => ['message' => "No such dispute: '{$id}'"]], 404);
            }

            if ($request->method() === 'GET') {
                return Http::response($dispute);
            }

            return $this->stripeKeeps($request, function () use ($path, $id, $dispute) {
                if ($this->stripeAnswersWith === 0) {
                    return null;
                }

                if ($this->stripeAnswersWith !== 200) {
                    return [['error' => ['type' => 'api_error', 'message' => 'An unknown error occurred.']], $this->stripeAnswersWith];
                }

                if (str_ends_with($path, '/close')) {
                    return [$this->stripeDisputes[$id] = array_replace($dispute, ['status' => 'lost']), 200];
                }

                return [$this->stripeDisputes[$id] = array_replace_recursive($dispute, [
                    'status' => 'under_review',
                    'evidence_details' => ['has_evidence' => true, 'submission_count' => ($dispute['evidence_details']['submission_count'] ?? 0) + 1],
                ]), 200];
            });
        };
    }

    /**
     * Stripe's idempotency: the first answer to a key is the answer to it.
     * An answer of null is none at all — the connection dropped — and leaves
     * nothing kept.
     *
     * @param  callable(): (array{0: array<string, mixed>, 1: int}|null)  $answer
     */
    private function stripeKeeps(ClientRequest $request, callable $answer): mixed
    {
        $key = $request->header('Idempotency-Key')[0] ?? null;

        if ($key !== null && isset($this->stripeKeptAnswers[$key])) {
            return Http::response(...$this->stripeKeptAnswers[$key]);
        }

        $answered = $answer();

        if ($answered === null) {
            return Http::failedConnection()($request);
        }

        if ($key !== null) {
            $this->stripeKeptAnswers[$key] = $answered;
        }

        return Http::response(...$answered);
    }

    /** Stripe announcing a dispute, signed as Stripe signs it. */
    protected function stripeDisputeNotice(array $dispute, string $type = 'charge.dispute.created'): TestResponse
    {
        $this->stripeDisputes[$dispute['id']] = $dispute;

        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $dispute],
        ]);

        $timestamp = time();

        return $this->call('POST', '/webhooks/payments/stripe',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, $this->stripeWebhookSecret),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $payload,
        );
    }

    /** Open a Stripe dispute on an order and return it as recorded. */
    protected function disputeOnStripe(Order $order, string $charge, string $reason, array $overrides = []): Dispute
    {
        $this->stripeAnswersDisputes();
        $dispute = $this->stripeDispute($order, $charge, $reason, $overrides);

        $this->stripeDisputeNotice($dispute)->assertOk();

        return Dispute::query()->where('gateway_reference', $dispute['id'])->sole();
    }

    /**
     * A Paystack dispute, as Paystack's charge.dispute.create notice and its
     * GET /dispute/:id both carry it: the transaction as an object, the
     * card's first six (which must never be kept), the buyer's words.
     *
     * @return array<string, mixed>
     */
    protected function paystackDispute(Order $order, int $transactionId, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => random_int(100000, 999999),
            'refund_amount' => $order->total_amount,
            'currency' => 'NGN',
            'status' => 'awaiting-merchant-feedback',
            'resolution' => null,
            'domain' => 'test',
            'transaction' => [
                'id' => $transactionId,
                'domain' => 'test',
                'status' => 'success',
                'reference' => $order->gateway_reference,
                'amount' => $order->total_amount,
                'message' => null,
                'gateway_response' => 'Approved',
                'paid_at' => now()->subDay()->toIso8601ZuluString(),
                'created_at' => now()->subDay()->toIso8601ZuluString(),
                'channel' => 'card',
                'currency' => 'NGN',
                'ip_address' => self::BUYER_ADDRESS,
                'metadata' => '',
                'fees' => 53,
                'paidAt' => now()->subDay()->toIso8601ZuluString(),
            ],
            'transaction_reference' => null,
            'category' => 'chargeback',
            'customer' => [
                'id' => 58364085,
                'first_name' => 'Tunde',
                'last_name' => 'Bello',
                'email' => $order->buyer_email,
                'customer_code' => 'CUS_'.Str::lower(Str::random(15)),
                'phone' => '08031234567',
                'metadata' => [],
                'risk_action' => 'default',
            ],
            'bin' => '408408',
            'last4' => '4081',
            'dueAt' => now()->addDays(3)->toIso8601ZuluString(),
            'resolvedAt' => null,
            'evidence' => null,
            'attachments' => null,
            'note' => null,
            'history' => [['status' => 'pending', 'by' => $order->buyer_email, 'createdAt' => now()->toIso8601ZuluString()]],
            'messages' => [['sender' => $order->buyer_email, 'body' => 'I paid and never got my tickets.', 'createdAt' => now()->toIso8601ZuluString()]],
            'createdAt' => now()->toIso8601ZuluString(),
            'updatedAt' => now()->toIso8601ZuluString(),
        ], $overrides);
    }

    /** Paystack's dispute API, answering from $paystackDisputes, with its own signed upload address. */
    protected function paystackAnswersDisputes(): void
    {
        $this->processor['api.paystack.co/dispute/*'] = function (ClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $parts = explode('/', trim(Str::after($path, '/dispute/'), '/'));
            $dispute = $this->paystackDisputes[$parts[0]] ?? null;
            $step = $parts[1] ?? null;

            if ($dispute === null) {
                return Http::response(['status' => false, 'message' => 'Dispute not found'], 404);
            }

            return match (true) {
                $step === null => Http::response(['status' => true, 'message' => 'Dispute retrieved', 'data' => $dispute]),
                // Each piece of evidence its own id: 21, then 22.
                $step === 'evidence' => Http::response(['status' => true, 'message' => 'Evidence created', 'data' => [
                    'customer_email' => $request['customer_email'],
                    'customer_name' => $request['customer_name'],
                    'customer_phone' => $request['customer_phone'],
                    'service_details' => $request['service_details'],
                    'dispute' => $dispute['id'],
                    'id' => 21 + $this->paystackEvidenceGiven++,
                    'createdAt' => now()->toIso8601ZuluString(),
                    'updatedAt' => now()->toIso8601ZuluString(),
                ]]),
                $step === 'upload_url' => Http::response(['status' => true, 'message' => 'Upload url generated', 'data' => [
                    'signedUrl' => 'https://s3.eu-west-1.amazonaws.com/files.paystack.co/disputes/qesp8a4df1xejihd9x5q?X-Amz-Expires=300',
                    'fileName' => 'qesp8a4df1xejihd9x5q.pdf',
                ]]),
                // Paystack answers a resolution with the dispute, its
                // transaction by id alone.
                $step === 'resolve' => Http::response(['status' => true, 'message' => 'Dispute successfully resolved', 'data' => array_replace($dispute, [
                    'status' => 'resolved',
                    'resolution' => $request['resolution'],
                    'transaction' => $dispute['transaction']['id'],
                    'evidence' => $request->data()['evidence'] ?? null,
                    'resolvedAt' => now()->toIso8601ZuluString(),
                ])]),
                default => Http::response(['status' => false, 'message' => 'Not faked'], 404),
            };
        };

        $this->processor['s3.eu-west-1.amazonaws.com/*'] = fn () => Http::response('', 200);
    }

    /** Paystack announcing a dispute, signed as Paystack signs it. */
    protected function paystackDisputeNotice(array $dispute, string $event = 'charge.dispute.create'): TestResponse
    {
        $this->paystackDisputes[(string) $dispute['id']] = $dispute;

        $payload = json_encode(['event' => $event, 'data' => $dispute]);

        return $this->call('POST', '/webhooks/payments/paystack',
            server: [
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, $this->paystackSecret),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $payload,
        );
    }

    /** @return list<ClientRequest> what was sent to a processor URL containing $fragment */
    protected function sentTo(string $fragment, ?string $method = null): array
    {
        return array_values(array_filter(
            $this->sentToProcessor,
            fn (ClientRequest $request) => str_contains($request->url(), $fragment) && ($method === null || $request->method() === $method),
        ));
    }

    /** Every code the order's tickets carry, which must appear nowhere a bank reads. */
    protected function ticketCodes(Order $order): array
    {
        return Ticket::query()->where('order_id', $order->id)->pluck('code')->all();
    }
}
