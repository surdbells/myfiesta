<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What an address has asked not to receive.
 *
 * Addresses are normalised on the way in. Somebody who unsubscribes as
 * Ada@Example.com and then gets mail addressed to ada@example.com has, from
 * where they are sitting, been ignored.
 */
class EmailPreference extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reminders_opted_out_at' => 'datetime',
            'marketing_opted_out_at' => 'datetime',
        ];
    }

    public static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * The row for an address, created if it is new.
     *
     * Every recipient gets one the first time they are sent anything, so the
     * unsubscribe link in that first email already has a token behind it.
     */
    public static function forEmail(string $email): self
    {
        return static::firstOrCreate(
            ['email' => static::normalise($email)],
            ['token' => Str::random(48)],
        );
    }

    public function wantsReminders(): bool
    {
        return $this->reminders_opted_out_at === null;
    }

    /** @return array<string, bool> the addresses that still want reminders */
    public static function remindable(array $emails): array
    {
        $normalised = array_unique(array_map(static::normalise(...), $emails));

        $optedOut = static::query()
            ->whereIn('email', $normalised)
            ->whereNotNull('reminders_opted_out_at')
            ->pluck('email')
            ->flip();

        return array_values(array_filter(
            $normalised,
            fn (string $email) => ! $optedOut->has($email),
        ));
    }
}
