<?php

namespace Tests\Unit;

use App\Contracts\Payments\PaymentEvent;
use App\Services\Disputes\Details;
use PHPUnit\Framework\TestCase;

/**
 * The date a chargeback has to be answered by, from either processor.
 *
 * It is the one date that decides whether a dispute can still be won, and
 * each processor puts it somewhere different — Paystack in camel case among
 * snake case, which is how it went unread.
 */
class DisputeDetailsTest extends TestCase
{
    private function paystack(array $dispute): PaymentEvent
    {
        return new PaymentEvent(
            type: PaymentEvent::DISPUTED,
            reference: 'T896467688',
            amountMinorUnits: 580000,
            currency: 'NGN',
            eventId: 'charge.dispute.create:358950',
            raw: ['event' => 'charge.dispute.create', 'data' => $dispute],
        );
    }

    public function test_paystacks_deadline_is_read_as_paystack_writes_it(): void
    {
        // The shape of Paystack's charge.dispute.create, trimmed.
        $details = Details::from($this->paystack([
            'id' => 358950,
            'refund_amount' => 580000,
            'currency' => 'NGN',
            'status' => 'awaiting-merchant-feedback',
            'category' => 'chargeback',
            'dueAt' => '2026-10-02T18:00:00.000Z',
            'resolvedAt' => null,
        ]));

        $this->assertSame('2026-10-02T18:00:00+00:00', $details->evidenceDueAt?->utc()->toIso8601String());
        $this->assertSame('358950', $details->reference);
        $this->assertSame(580000, $details->amount);
        $this->assertSame('chargeback', $details->reason);
    }

    public function test_the_snake_cased_spelling_is_read_too(): void
    {
        $details = Details::from($this->paystack([
            'id' => 358950,
            'due_at' => '2026-10-02T18:00:00.000Z',
        ]));

        $this->assertSame('2026-10-02T18:00:00+00:00', $details->evidenceDueAt?->utc()->toIso8601String());
    }

    public function test_no_deadline_is_no_deadline_rather_than_today(): void
    {
        $this->assertNull(Details::from($this->paystack(['id' => 358950, 'dueAt' => null]))->evidenceDueAt);
        $this->assertNull(Details::from($this->paystack(['id' => 358950]))->evidenceDueAt);
    }

    public function test_stripes_deadline_is_still_read_from_its_own_place(): void
    {
        $details = Details::from(new PaymentEvent(
            type: PaymentEvent::DISPUTED,
            reference: 'ch_1',
            amountMinorUnits: 11300,
            currency: 'CAD',
            eventId: 'evt_1',
            raw: ['type' => 'charge.dispute.created', 'data' => ['object' => [
                'id' => 'dp_1',
                'amount' => 11300,
                'currency' => 'cad',
                'reason' => 'product_not_received',
                'evidence_details' => ['due_by' => 1790964000],
            ]]],
        ));

        $this->assertSame(1790964000, $details->evidenceDueAt?->getTimestamp());
        $this->assertSame('CAD', $details->currency);
    }
}
