<?php

namespace App\Enums;

/**
 * What somebody may do, named once.
 *
 * Roles answer "who is this person"; permissions answer "may they do this". The
 * platform had only the first, with the role list for each capability written
 * inline at every call site — and written a second time, by hand, in the
 * console's session store. The two had already drifted: the API grants sales
 * figures to Owner, Manager and Finance, and the console showed them to Owner
 * and Finance, so a Manager saw no revenue on an event they were entitled to.
 *
 * That is the benign direction. Reversed, the same duplication shows somebody a
 * button that 403s. Nothing tested the two against each other, so the next
 * divergence would have been just as quiet.
 *
 * This enum is the single authority. Policies resolve against it, and the API
 * sends the resolved set to the client, which checks membership rather than
 * recomputing anything. There is no second copy to drift.
 */
enum Permission: string
{
    // Events
    case EventsView = 'events.view';
    case EventsCreate = 'events.create';
    case EventsEdit = 'events.edit';
    case EventsPublish = 'events.publish';
    case EventsCancel = 'events.cancel';
    case EventsDelete = 'events.delete';

    // Selling
    case TicketsManage = 'tickets.manage';
    case CodesManage = 'codes.manage';

    // The night itself
    case AttendeesView = 'attendees.view';
    case DoorScan = 'door.scan';

    // Money. Deliberately split: seeing what an event made and moving money
    // back out of it are different responsibilities, and the second is the one
    // that cannot be undone.
    case MoneyView = 'money.view';
    case RefundsProcess = 'refunds.process';

    // Asking the platform to send what is owed. Owners and finance: the
    // people whose job is where the money goes, not everybody who can see it.
    case PayoutsRequest = 'payouts.request';

    // Reaching the audience
    case MessagesSend = 'messages.send';

    // Who is on the team, and in what role. Owners only: handing somebody the
    // payouts screen is an owner's decision.
    case TeamManage = 'team.manage';

    /**
     * The organization's public face: its name, what it says about itself, and
     * its mark.
     *
     * Owner-only, like the team. This is the identity every event page is
     * published under, and a stranger deciding whether to hand over forty
     * dollars is partly deciding about it.
     */
    case BrandManage = 'organization.brand';

    /**
     * Every permission a role carries.
     *
     * Written as one table rather than scattered through policies, because the
     * question an owner actually asks is "what can a Marketing person do?" and
     * that should be answerable by reading one screen of code.
     *
     * @return list<self>
     */
    public static function forRole(Role $role): array
    {
        return match ($role) {
            /*
             * Everything, including the two nobody else gets: taking an event
             * off sale permanently, and deleting one. Both were already
             * owner-only in EventPolicy::delete and stay that way.
             */
            Role::Owner => self::cases(),

            // Runs events end to end and works the door. Cannot cancel or
            // delete an event — those were owner-only before this refactor and
            // are the two actions that cannot be walked back.
            Role::Manager => [
                self::EventsView, self::EventsCreate, self::EventsEdit, self::EventsPublish,
                self::TicketsManage, self::CodesManage,
                self::AttendeesView, self::DoorScan,
                self::MoneyView, self::RefundsProcess,
                self::MessagesSend,
            ],

            // Money in and money out, and nothing that changes what is on sale.
            Role::Finance => [
                self::EventsView,
                self::MoneyView, self::RefundsProcess, self::PayoutsRequest,
            ],

            // Fills the room: the guest list, promoter codes and the audience.
            // Never what anything earned.
            Role::Marketing => [
                self::EventsView,
                self::AttendeesView,
                self::CodesManage,
                self::MessagesSend,
            ],

            /*
             * Venue staff hired for one night, often on a shared phone handed
             * over at 10pm.
             *
             * Scanning and nothing else. The guest list is deliberately absent:
             * EventPolicy::viewGuests has always excluded door staff, on the
             * grounds that being able to check somebody in does not carry the
             * right to read everybody's name and address.
             */
            Role::Door => [
                self::EventsView,
                self::DoorScan,
            ],
        };
    }

    /** @return list<string> */
    public static function namesForRole(Role $role): array
    {
        return array_map(fn (self $p) => $p->value, self::forRole($role));
    }
}
