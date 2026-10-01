<?php

namespace App\Services\Tickets;

use App\Mail\YourTicket;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Moving a ticket to somebody else, however it is asked for.
 *
 * Three ways in, one way through: a holder on the phone (TicketController),
 * a buyer on the link in their email (TicketTransferController), and support
 * moving it for somebody who asked (TicketActions::reissue). Each used to do
 * its own version, and the phone's skipped most of it — no lock, no check of
 * how many had already come in on a table, the same code before and after,
 * and no email to the person it went to.
 *
 * In one locked transaction, so two taps on a slow connection, or a send and
 * a return racing each other, cannot both move the same ticket:
 * - only while it is still with whoever was allowed to send it: the caller
 *   checked that against the ticket as it read it, before the lock, and two
 *   sends to different addresses both pass that check;
 * - only a working ticket nobody has come in on, and not one given back;
 * - not once the night has started (unless support is moving it);
 * - not to the address that already holds it;
 * - to the account at that address, however it was typed (User::forAddress);
 * - written down as a transfer, with a link of its own for the new holder,
 *   closing the one the last holder was sent (TicketLink);
 * - with a new code, so the one in the old holder's email, on their phone or
 *   in a screenshot stops opening the door.
 * Then, once it is settled, the audit trail and the new holder's email.
 */
class TicketHandover
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Why the holder cannot send this one on now, or null when they can.
     *
     * Not locked: this is what a screen shows beside the ticket. Sending it
     * asks again, under the lock.
     */
    public function refusal(Ticket $ticket, ?Event $event): ?string
    {
        return $this->refused($ticket, $event, untilDoors: true)?->getMessage();
    }

    /**
     * A holder sends their own ticket on.
     *
     * Always with a new code. The old one is in an email, on a phone and
     * perhaps in a screenshot, and a ticket that still works for whoever sent
     * it has not been sent so much as copied.
     *
     * @param  User|null  $holder  the account sending it, or null from the order's link
     *
     * @throws TicketHandoverRefused
     */
    public function send(Ticket $ticket, string $email, string $name, ?User $holder): TicketTransfer
    {
        // Who it is from, for the new holder's email: the name on the ticket
        // as it was, which is what the sender called themselves.
        $from = $holder->name ?? $ticket->holder_name ?? $ticket->order->buyer_name ?? null;

        $transfer = $this->move($ticket, $email, $name, $holder, newCode: true, untilDoors: true,
            via: $holder === null ? 'link' : 'app', heldBy: self::holderOf($ticket));

        $this->auditor->record('ticket.transferred', $transfer->ticket, $holder, metadata: [
            'transfer_id' => $transfer->id,
            'via' => $holder === null ? 'link' : 'app',
            'new_code' => true,
        ]);

        $this->tell($transfer, reissued: false, newCode: true, from: $from);

        return $transfer;
    }

    /**
     * Support moves a ticket for somebody who asked.
     *
     * Not closed at the start of the night: somebody at the front of the
     * queue with the wrong address on their ticket is who this is for. A new
     * code by default, as for a holder; support may keep the old one for a
     * holder who has it and cannot receive email.
     *
     * @throws TicketHandoverRefused
     */
    public function reissue(Ticket $ticket, string $email, string $name, User $staff, bool $newCode = true, ?string $reason = null): TicketTransfer
    {
        $transfer = $this->move($ticket, $email, $name, $staff, newCode: $newCode, untilDoors: false, via: 'support');

        $this->auditor->record('ticket.reissued', $transfer->ticket, $staff, metadata: array_filter([
            'transfer_id' => $transfer->id,
            'new_code' => $newCode,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ], fn ($value) => $value !== null));

        $this->tell($transfer, reissued: true, newCode: $newCode, from: null);

        return $transfer;
    }

    /**
     * Where the new holder opens their ticket: a page with that ticket and
     * nothing else of the order's, for as long as it is theirs.
     */
    public static function link(TicketTransfer $transfer): string
    {
        return rtrim((string) config('app.public_url'), '/').'/tickets/'.$transfer->access_token;
    }

    /**
     * Who holds a ticket, as the caller saw it when it decided they may send it.
     *
     * @return array{user: ?string, email: string}
     */
    private static function holderOf(Ticket $ticket): array
    {
        return [
            'user' => $ticket->owner_user_id,
            'email' => Str::lower(trim((string) $ticket->owner_email)),
        ];
    }

    /**
     * @param  'app'|'link'|'support'  $via  how it was asked for, for the admin panel's history
     * @param  array{user: ?string, email: string}|null  $heldBy  who the caller allowed to send it; null for support
     *
     * @throws TicketHandoverRefused
     */
    private function move(Ticket $ticket, string $email, string $name, ?User $by, bool $newCode, bool $untilDoors, string $via, ?array $heldBy = null): TicketTransfer
    {
        $email = Str::lower(trim($email));
        $name = trim($name);

        return DB::transaction(function () use ($ticket, $email, $name, $by, $newCode, $untilDoors, $via, $heldBy) {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            // The caller's check was of the ticket as it read it. Two sends to
            // different addresses at once both pass it, and without asking
            // again here the second would move the ticket on from whoever the
            // first just gave it to.
            if ($heldBy !== null && self::holderOf($locked) !== $heldBy) {
                throw new TicketHandoverRefused(TicketHandoverRefused::MOVED,
                    'This ticket has already been sent to somebody else.');
            }

            $refused = $this->refused($locked, $locked->event()->first(), $untilDoors);

            if ($refused !== null) {
                throw $refused;
            }

            if (Str::lower(trim((string) $locked->owner_email)) === $email) {
                throw new TicketHandoverRefused(TicketHandoverRefused::SAME_ADDRESS, 'This ticket is already at that address.');
            }

            // However the address was typed, deactivated accounts included:
            // one person is one account.
            $recipient = User::forAddress($email, $name);

            // The link the last holder was sent stops opening it. Only the
            // credential goes: the transfer itself stays on the record.
            TicketTransfer::query()
                ->where('ticket_id', $locked->id)
                ->whereNotNull('access_token')
                ->update(['access_token' => null]);

            $transfer = TicketTransfer::create([
                'ticket_id' => $locked->id,
                'from_email' => $locked->owner_email ?? $locked->order->buyer_email ?? '',
                'to_email' => $recipient->email,
                'to_user_id' => $recipient->id,
                'initiated_by' => $by?->id,
                'via' => $via,
                'transferred_at' => now(),
                'access_token' => Str::random(44),
            ]);

            $changes = [
                'owner_user_id' => $recipient->id,
                'owner_email' => $recipient->email,
                'holder_name' => $name,
            ];

            if ($newCode) {
                $changes['code'] = $this->freshCode();
            }

            $locked->update($changes);

            return $transfer->setRelation('ticket', $locked);
        });
    }

    /**
     * The reason this ticket cannot move, or null.
     *
     * Whether anybody has come in is read from the count as well as the
     * status: a table of five with two inside is still `valid`, and sending
     * it on would hand three places to somebody who never met the two.
     */
    private function refused(Ticket $ticket, ?Event $event, bool $untilDoors): ?TicketHandoverRefused
    {
        if ($ticket->status === 'checked_in') {
            return new TicketHandoverRefused(TicketHandoverRefused::USED, 'This ticket has already been used.');
        }

        if ($ticket->status === 'listed') {
            return new TicketHandoverRefused(TicketHandoverRefused::LISTED,
                'This ticket is waiting for somebody to take it. Keep it first if you would rather send it to somebody.');
        }

        if ($ticket->status !== 'valid') {
            return new TicketHandoverRefused(TicketHandoverRefused::GONE, 'This ticket can no longer be transferred.');
        }

        if ((int) $ticket->admitted_count > 0) {
            return new TicketHandoverRefused(TicketHandoverRefused::PARTLY_USED,
                'Somebody has already come in on this ticket, so it stays with you.');
        }

        // A door phone working with no signal holds the list it downloaded,
        // old codes and all. Closing at the start of the night keeps that
        // window to the time before the doors.
        if ($untilDoors && $event?->starts_at !== null && ! $event->starts_at->isFuture()) {
            return new TicketHandoverRefused(TicketHandoverRefused::STARTED,
                'The event has started, so tickets can no longer be sent to somebody else.');
        }

        return null;
    }

    /**
     * The new holder's email, with their ticket and the link to it.
     *
     * Queued, and read again when it is sent: if the ticket has moved on in
     * between, the email says so rather than showing a code that is now
     * somebody else's (YourTicket).
     */
    private function tell(TicketTransfer $transfer, bool $reissued, bool $newCode, ?string $from): void
    {
        $ticket = $transfer->ticket->fresh(['event.venue', 'event.organization', 'ticketType']);

        Mail::to($transfer->to_email)->send(new YourTicket(
            $ticket,
            reissued: $reissued,
            newCode: $newCode,
            link: self::link($transfer),
            sender: $from,
        ));
    }

    private function freshCode(): string
    {
        do {
            $code = Ticket::generateCode();
        } while (Ticket::where('code', $code)->exists());

        return $code;
    }
}
