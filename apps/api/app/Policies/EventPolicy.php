<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Event;
use App\Models\User;

/**
 * Who may do what to an event.
 *
 * Every method resolves against Permission rather than naming roles inline.
 * That is the whole point of the change: the role list for a capability used to
 * be written here and then written again, by hand, in the console — and the two
 * had already drifted on sales figures.
 *
 * Behaviour is unchanged by the refactor. Each permission below is granted to
 * exactly the roles its method named before.
 */
class EventPolicy
{
    /** Anyone may see a published event; staff may see their own drafts. */
    public function view(?User $user, Event $event): bool
    {
        if ($event->status === 'published') {
            return true;
        }

        return $user !== null && $user->isStaffOf($event->organization_id);
    }

    public function create(User $user, string $organizationId): bool
    {
        return $user->hasPermissionIn($organizationId, Permission::EventsCreate);
    }

    public function update(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::EventsEdit);
    }

    public function publish(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::EventsPublish);
    }

    /**
     * Take an event off permanently.
     *
     * Owner only, and separate from publish. Unpublishing hides a link;
     * cancelling tells everybody holding a ticket that the night is not
     * happening and starts refunds. They are not the same decision.
     */
    public function cancel(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::EventsCancel);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::EventsDelete);
    }

    /** Ticket types, prices and capacity. */
    public function manageTickets(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::TicketsManage);
    }

    /**
     * The guest list, and who has arrived.
     *
     * Door staff are excluded on purpose. Scanning is a separate ability, tied
     * to one event, and does not carry the right to read the list.
     */
    public function viewGuests(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::AttendeesView);
    }

    public function viewSales(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::MoneyView);
    }

    /**
     * Send money back.
     *
     * Narrower than reading the numbers. Finance is here because returning
     * money is finance's job, and a manager is here because refunds are asked
     * for at the door and on the night. Marketing is not: seeing what an event
     * took is a long way from being able to move it.
     */
    public function refund(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::RefundsProcess);
    }

    /**
     * Check people in.
     *
     * The only capability door staff hold, and it is granted per event: a
     * `door:{event_id}` token cannot be pointed at a different door.
     */
    public function scan(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::DoorScan);
    }

    public function message(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::MessagesSend);
    }

    /** Discount codes and promoter links. */
    public function manageCodes(User $user, Event $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::CodesManage);
    }
}
