<?php

namespace App\Events;

use App\Events\Concerns\SaidOnceCommitted;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody walked in on a ticket.
 *
 * Said by CheckInService each time a scan counts people in: `admitted` is how
 * many this scan added, not the ticket's total, so a table arriving in two
 * groups is said twice. `source` is whether the door was online, or synced
 * what it did with no signal afterwards. Never for a refusal, a scan already
 * recorded, or a question about how many are there — nobody went in.
 *
 * Heard only once the transaction has committed, like OrderPaid.
 */
final class TicketAdmitted implements ShouldDispatchAfterCommit
{
    use SaidOnceCommitted, SerializesModels;

    public const ONLINE = 'online';

    public const OFFLINE_SYNC = 'offline_sync';

    /** @param  self::ONLINE|self::OFFLINE_SYNC  $source */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly Event $event,
        public readonly int $admitted,
        public readonly string $source,
    ) {}
}
