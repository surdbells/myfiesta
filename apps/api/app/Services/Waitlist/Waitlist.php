<?php

namespace App\Services\Waitlist;

use App\Mail\WaitlistOpenedMail;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Joining, telling, and closing the loop.
 */
class Waitlist
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * Whether a buyer could buy anything right now without a code.
     *
     * The waitlist is only offered when they could not: a list on an event
     * with tickets on sale collects people who should simply have bought one.
     */
    public function hasTicketsOnSale(Event $event): bool
    {
        return $event->ticketTypes()
            ->where('status', 'on_sale')
            ->get()
            ->contains(fn (TicketType $type) => ! $type->isLocked() && ! $type->isExhausted());
    }

    /**
     * Add somebody, or update how many they want if they are already on it.
     *
     * Rejoining after being told, or after leaving, puts them back to waiting
     * at the back of the list — which is fair to everyone who stayed.
     */
    public function join(Event $event, string $email, ?string $name, int $quantity): WaitlistEntry
    {
        $email = Str::lower(trim($email));

        $entry = WaitlistEntry::firstOrNew(['event_id' => $event->id, 'email' => $email]);

        if (! $entry->exists || $entry->status !== 'waiting') {
            $entry->token = Str::random(48);
            $entry->status = 'waiting';
            $entry->notified_at = null;
            $entry->created_at = now();
        }

        $entry->name = $name ?: $entry->name;
        $entry->quantity = $quantity;
        $entry->save();

        return $entry;
    }

    /**
     * Email the people waiting, first come first told.
     *
     * @param  int|null  $limit  how many to tell; null for everyone waiting
     * @return int how many were told
     */
    public function notify(Event $event, User $by, ?int $limit, ?string $note): int
    {
        $entries = WaitlistEntry::query()
            ->where('event_id', $event->id)
            ->where('status', 'waiting')
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        foreach ($entries as $entry) {
            // Claimed before sending, so a double click cannot mail anybody twice.
            $claimed = WaitlistEntry::query()
                ->whereKey($entry->id)
                ->where('status', 'waiting')
                ->update(['status' => 'notified', 'notified_at' => now(), 'updated_at' => now()]);

            if ($claimed === 1) {
                Mail::to($entry->email)->queue(new WaitlistOpenedMail($entry->fresh(), $note));
            }
        }

        $this->auditor->record('waitlist.notified', $event, $by, $event->organization_id, [
            'told' => $entries->count(),
            'limit' => $limit,
        ]);

        return $entries->count();
    }

    /** A paid order from somebody on the list: they got in. */
    public function markPurchased(string $eventId, string $email): void
    {
        WaitlistEntry::query()
            ->where('event_id', $eventId)
            ->where('email', Str::lower(trim($email)))
            ->whereIn('status', ['waiting', 'notified'])
            ->update(['status' => 'purchased', 'purchased_at' => now(), 'updated_at' => now()]);
    }
}
