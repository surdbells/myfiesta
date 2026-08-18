<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;

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
        return $user->hasRoleIn($organizationId, Role::Owner, Role::Manager);
    }

    public function update(User $user, Event $event): bool
    {
        return $user->hasRoleIn($event->organization_id, Role::Owner, Role::Manager);
    }

    public function publish(User $user, Event $event): bool
    {
        return $user->hasRoleIn($event->organization_id, Role::Owner, Role::Manager);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->hasRoleIn($event->organization_id, Role::Owner);
    }

    /**
     * The guest list, and who has arrived.
     *
     * Door staff are excluded on purpose. Scanning is a separate ability, tied
     * to one event, and does not carry the right to read the list.
     */
    public function viewGuests(User $user, Event $event): bool
    {
        return $user->hasRoleIn($event->organization_id, Role::Owner, Role::Manager, Role::Marketing);
    }

    public function viewSales(User $user, Event $event): bool
    {
        return $user->hasRoleIn($event->organization_id, Role::Owner, Role::Manager, Role::Finance);
    }

    /**
     * Check people in.
     *
     * The only capability door staff hold, and it is granted per event: a
     * `door:{event_id}` token cannot be pointed at a different door.
     */
    public function scan(User $user, Event $event): bool
    {
        return $user->hasRoleIn(
            $event->organization_id,
            Role::Owner, Role::Manager, Role::Door,
        );
    }

    public function message(User $user, Event $event): bool
    {
        return $user->hasRoleIn($event->organization_id, Role::Owner, Role::Manager, Role::Marketing);
    }
}
