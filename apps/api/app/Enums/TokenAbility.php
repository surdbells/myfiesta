<?php

namespace App\Enums;

/**
 * Sanctum token abilities.
 *
 * The mobile app carries attendee, organizer, and door modes in one binary, so
 * a door-staff phone has the organizer interface compiled into it — hidden, but
 * present. Hiding it is a convenience; the boundary is these abilities, checked
 * server-side. If the API is permissive here, a door-staff phone becomes an
 * organizer console and we have rebuilt the defect this replaces.
 */
enum TokenAbility: string
{
    /** Browse, buy, and manage one's own tickets. */
    case Attendee = 'attendee';

    /** Full access to organizations the user belongs to, subject to role. */
    case Organizer = 'organizer';

    /**
     * Scan and check in for a single event, and nothing else.
     *
     * Always issued as `door:{event_id}` so the grant cannot outlive or escape
     * the event it was made for.
     */
    case Door = 'door';

    public static function doorFor(string $eventId): string
    {
        return self::Door->value.':'.$eventId;
    }

    /** Endpoints a door token must never reach, regardless of the user's role. */
    public const DENIED_TO_DOOR = [
        'orders', 'sales', 'guests', 'payouts', 'settlements',
        'profile', 'codes', 'newsletters', 'identity',
    ];
}
