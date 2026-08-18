<?php

namespace App\Enums;

/**
 * Roles a user holds within an organization.
 *
 * Mirrors the check constraint on organization_user.role — the database is the
 * final word, this enum is how application code talks about it. Adding a case
 * here without adding it there will fail at write time, which is the intent.
 */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Finance = 'finance';
    case Marketing = 'marketing';

    /**
     * The narrowest role. Venue staff hired for one night get this and nothing
     * else: they can scan, and they cannot see sales, guests, or payouts.
     */
    case Door = 'door';

    /** Roles that may act on behalf of the organization at all. */
    public function isStaff(): bool
    {
        return $this !== self::Door;
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Finance => 'Finance',
            self::Marketing => 'Marketing',
            self::Door => 'Door staff',
        };
    }
}
