<?php

namespace App\Services\PersonalData;

use App\Models\EmailPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The person a request is about: an address, and an account if there is one.
 *
 * Guest checkout means the address is the identity for most people here. An
 * account is an extra half of the map to walk, not a precondition for having
 * any rights.
 */
class Subject
{
    public readonly string $email;

    public function __construct(string $email, public readonly ?User $user = null)
    {
        $this->email = EmailPreference::normalise($email);
    }

    public static function forEmail(string $email): self
    {
        $normalised = EmailPreference::normalise($email);

        return new self($normalised, User::withTrashed()->whereRaw('lower(email) = ?', [$normalised])->first());
    }

    /**
     * Whether anything at all is held about this person.
     *
     * Asked before a verification email goes out, so somebody who types a
     * stranger's address into the form cannot use us to send them mail. The
     * answer is never given back to whoever asked.
     *
     * The suppression list alone does not count as knowing somebody: an
     * address that has only ever unsubscribed is one we hold in order to
     * leave it alone.
     */
    public function isKnown(): bool
    {
        if ($this->user !== null) {
            return true;
        }

        foreach (config('personal_data.by_email') as $table => $spec) {
            if ($table === 'email_preferences' || $table === 'data_requests') {
                continue;
            }

            $exists = DB::table($table)->whereRaw("lower({$spec['key']}) = ?", [$this->email])->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /** The value that identifies this person in a given section of the map. */
    public function keyFor(string $section): string|int|null
    {
        return $section === 'by_user' ? $this->user?->id : $this->email;
    }
}
