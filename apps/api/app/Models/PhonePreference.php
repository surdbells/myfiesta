<?php

namespace App\Models;

use App\Services\Sms\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * A number that has asked not to be texted.
 *
 * Honoured for every message, including the ticket itself. Somebody who says
 * stop has said stop; the ticket is in their inbox either way, and deciding
 * that our most important message is the exception is how a suppression list
 * stops meaning anything.
 */
class PhonePreference extends Model
{
    protected $table = 'phone_preferences';

    protected $primaryKey = 'phone';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }

    public static function hasOptedOut(string $phone): bool
    {
        $number = PhoneNumber::e164($phone);

        return $number !== null && static::query()->whereKey($number)->exists();
    }

    /** @return bool whether this was new */
    public static function optOut(string $phone, ?string $via = null): bool
    {
        $number = PhoneNumber::e164($phone);

        if ($number === null || static::query()->whereKey($number)->exists()) {
            return false;
        }

        static::create(['phone' => $number, 'opted_out_at' => now(), 'via' => $via]);

        return true;
    }

    /** Somebody who texted START after stopping. */
    public static function optIn(string $phone): void
    {
        $number = PhoneNumber::e164($phone);

        if ($number !== null) {
            static::query()->whereKey($number)->delete();
        }
    }
}
