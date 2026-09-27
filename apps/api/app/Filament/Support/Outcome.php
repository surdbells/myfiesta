<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Services\Refunds\RefundRefused;
use App\Services\StaffSupport\StaffActionRefused;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Run a staff action and tell the person at the screen how it went.
 *
 * Refusals are written to be read, so they are shown as they are. Anything
 * else escapes: an exception nobody wrote a sentence for belongs in the error
 * log, not dressed up as a notification.
 */
final class Outcome
{
    /**
     * @param  Closure(User): (string|Notification|null|void)  $do  returns an optional sentence for the
     *                                                              success notice, or a whole notice when
     *                                                              the outcome is neither done nor refused
     */
    public static function run(Action $action, string $done, Closure $do): void
    {
        $staff = auth()->user();

        if (! $staff instanceof User) {
            abort(403);
        }

        try {
            $detail = $do($staff);
        } catch (StaffActionRefused|RefundRefused $refused) {
            Notification::make()
                ->title('Not done')
                ->body($refused->getMessage())
                ->danger()
                ->send();

            $action->halt();

            return;
        }

        if ($detail instanceof Notification) {
            $detail->send();

            return;
        }

        Notification::make()
            ->title($done)
            ->body(is_string($detail) ? $detail : null)
            ->success()
            ->send();
    }
}
