<?php

namespace App\Services\StaffSupport;

use App\Enums\PlatformRole;
use App\Models\User;

/**
 * What platform staff may do to somebody else's account, order, ticket or
 * event, and which of them may do it.
 *
 * One table rather than role lists written out at each button. The panel hides
 * what a role cannot do, and the services behind the buttons check again with
 * this same table — so a hidden button and a refused call can never disagree.
 *
 * Three tiers:
 *
 * - Every staff role answers people: resends what they already hold, signs an
 *   account out, writes a note on an order.
 * - Finance and administrators move value: refunds, voids, and moving a ticket
 *   to somebody else, which is how a ticket is stolen by phone if support can
 *   do it on request.
 * - Administrators alone change what the public sees or who can sign in:
 *   featuring, taking an event down, deactivating an account.
 */
enum StaffAction: string
{
    case Resend = 'resend';
    case SignOut = 'sign_out';
    case Note = 'note';
    case Refund = 'refund';
    case Void = 'void';
    case Reissue = 'reissue';
    case Feature = 'feature';
    case TakeDown = 'take_down';
    case Deactivate = 'deactivate';

    /** @return list<PlatformRole> */
    public function roles(): array
    {
        return match ($this) {
            self::Resend, self::SignOut, self::Note => [PlatformRole::Admin, PlatformRole::Finance, PlatformRole::Support],
            self::Refund, self::Void, self::Reissue => [PlatformRole::Admin, PlatformRole::Finance],
            self::Feature, self::TakeDown, self::Deactivate => [PlatformRole::Admin],
        };
    }

    public function allows(?User $staff): bool
    {
        return $staff !== null
            && $staff->deleted_at === null
            && $staff->hasPlatformRole(...$this->roles());
    }

    /** @throws StaffActionRefused */
    public function authorize(?User $staff): void
    {
        if (! $this->allows($staff)) {
            throw StaffActionRefused::because('Your role cannot do this. Ask an administrator.');
        }
    }

    /** For a panel closure: may whoever is signed in do this? */
    public static function current(self $action): bool
    {
        $user = auth()->user();

        return $user instanceof User && $action->allows($user);
    }
}
