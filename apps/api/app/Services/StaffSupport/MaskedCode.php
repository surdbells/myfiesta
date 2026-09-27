<?php

namespace App\Services\StaffSupport;

/**
 * A ticket code as staff see it: enough to match what a caller reads out,
 * never enough to get in.
 *
 * A code opens a door, so no admin screen, list or export carries one whole.
 * The tail is what somebody on the phone can read from their own email ("it
 * ends in 7KQ2") and is shown only when what stays hidden is still far too
 * much to guess — a short code imported from the old platform shows less, and
 * a very short one shows nothing.
 */
final class MaskedCode
{
    public static function of(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $plain = preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '';
        $length = strlen($plain);

        $shown = match (true) {
            $length >= 10 => 4,
            $length >= 7 => 2,
            default => 0,
        };

        return $shown === 0 ? '••••••' : '••••'.substr($plain, -$shown);
    }
}
