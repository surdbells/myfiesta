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

    /**
     * Whether an address still wants mail it did not ask for individually.
     *
     * An announcement from an organizer somebody follows is this kind: asked
     * for once, in general, rather than for this particular night — so the
     * blanket no has to stop it.
     */
    public function wantsMarketing(): bool
    {
        return $this->marketing_opted_out_at === null;
    }

    /** @return array<int, string> the addresses that still want announcements */
    public static function marketable(array $emails): array
    {
        return self::wanting($emails, 'marketing_opted_out_at');
    }

    public function wantsReminders(): bool
    {
        return $this->reminders_opted_out_at === null;
    }

    /** @return array<int, string> the addresses that still want reminders */
    public static function remindable(array $emails): array
    {
        return self::wanting($emails, 'reminders_opted_out_at');
    }

    /**
     * The addresses among these that have not opted out of one kind of mail.
     *
     * Asked in batches. One whereIn with every holder is one bound parameter
     * per address, and Postgres refuses a statement past 65,535 of them — so
     * the event big enough to matter was the one where messaging and reminders
     * failed outright.
     *
     * @return array<int, string>
     */
    private static function wanting(array $emails, string $column): array
    {
        $normalised = array_unique(array_map(static::normalise(...), $emails));

        $optedOut = collect(array_chunk($normalised, 5000))
            ->flatMap(fn (array $batch) => static::query()
                ->whereIn('email', $batch)
                ->whereNotNull($column)
                ->pluck('email'))
            ->flip();

        return array_values(array_filter(
            $normalised,
            fn (string $email) => ! $optedOut->has($email),
        ));
    }
}
