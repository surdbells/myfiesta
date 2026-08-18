<?php

namespace App\Enums;

/**
 * Employment at myFiesta, not membership of an organization.
 *
 * Mirrors the check constraint on users.platform_role. Null — the normal case —
 * means the person does not work here and cannot reach the admin panel at all.
 */
enum PlatformRole: string
{
    case Admin = 'admin';
    case Finance = 'finance';
    case Support = 'support';

    /**
     * Recording that a payout happened.
     *
     * Narrow on purpose: settlement is manual, so posting one asserts something
     * about the real world rather than instructing a payment processor.
     */
    public function canSettle(): bool
    {
        return in_array($this, [self::Admin, self::Finance], true);
    }

    /** Organizer banking details and identity documents. */
    public function canReadSensitiveData(): bool
    {
        return in_array($this, [self::Admin, self::Finance], true);
    }

    public function canReviewIdentityDocuments(): bool
    {
        return in_array($this, [self::Admin, self::Support], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Finance => 'Finance',
            self::Support => 'Support',
        };
    }
}
