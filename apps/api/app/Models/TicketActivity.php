<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to an order's tickets after the sale.
 *
 * Append-only, enforced by a database trigger, and deleted only by the
 * retention prune 18 months after the event. There is no updated_at and no
 * path here that writes to an existing row. ActivityLog is what writes these.
 *
 * @property string $kind
 * @property array<string, mixed>|null $details
 */
class TicketActivity extends Model
{
    use HasUuids;

    /** The order's tickets were minted. */
    public const ISSUED = 'tickets_issued';

    /** An email about the order or a ticket left for the mail provider. */
    public const EMAILED = 'email_sent';

    /** The ticket page behind the link in the confirmation email. */
    public const TICKET_PAGE = 'ticket_page_opened';

    /*
     * There is no row for the page a buyer lands on from the payment page.
     * It shows the order's status and no ticket, anybody holding the
     * reference can ask it, and the site's own server asks it while drawing
     * the page — so a row for it would tell a bank the tickets were opened,
     * from an address that was often ours.
     */

    /** The older signed link to an order's tickets. */
    public const ORDER_LINK = 'order_link_opened';

    /** The app's ticket list, which draws each ticket's QR. */
    public const QR_IN_APP = 'qr_shown_in_app';

    /** The night as a calendar file, from the ticket page. */
    public const CALENDAR = 'calendar_downloaded';

    /** Handed to somebody else; the ticket_transfers row says who and by whom. */
    public const TRANSFERRED = 'ticket_transferred';

    /**
     * The kinds that are somebody opening the tickets themselves, and so may
     * carry an address: each one shows the tickets, or needs the link that
     * does.
     */
    public const ACCESS = [self::TICKET_PAGE, self::ORDER_LINK, self::QR_IN_APP, self::CALENDAR];

    protected $table = 'ticket_activity';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => UtcDateTime::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(TicketTransfer::class, 'ticket_transfer_id');
    }
}
