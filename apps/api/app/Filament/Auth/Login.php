<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Session\StaffSignIn;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Component;
use Illuminate\Auth\SessionGuard;

/**
 * The staff sign-in: password, then the code emailed to the account's address.
 *
 * Filament does the two steps — the panel requires email codes, and the code
 * is only asked for once the password is right and the account may use the
 * admin at all. What this adds is the other half of what staff asked for:
 * once in, stay in. "Keep me signed in" is ticked by default and lasts the
 * staff remember lifetime, so a closed browser or a long weekend is not a
 * sign-out. It can still be unticked, which is the right thing on a shared
 * computer: then there is no remember cookie, and the session cookie ends
 * when the browser closes.
 *
 * The remember cookie is only ever issued after the code has been checked,
 * so coming back through it is not a way around the second step. The session
 * is marked as having passed the code (StaffSignIn), and AuthenticateStaff
 * signs out any admin session that was not.
 */
class Login extends FilamentLogin
{
    public function authenticate(): ?LoginResponse
    {
        $guard = Filament::auth();

        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration((int) config('session.staff_remember'));
        }

        $response = parent::authenticate();

        // Null while the code is still being asked for; a response only once
        // both steps have passed and the session id has been rotated.
        if ($response !== null && ($user = $guard->user()) instanceof User) {
            $keep = (bool) ($this->data['remember'] ?? false);

            StaffSignIn::record(session()->driver(), $user, $keep);

            // This response writes the new session's cookie. AuthenticateStaff
            // makes the same choice on every request after it.
            config(['session.expire_on_close' => ! $keep]);

            $user->forceFill(['last_login_at' => now()])->save();

            app(Auditor::class)->record(
                action: 'staff.signed_in',
                subject: $user,
                actor: $user,
                metadata: [
                    'role' => $user->platform_role?->value,
                    'kept_signed_in' => $keep,
                ],
            );
        }

        return $response;
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label('Keep me signed in on this browser')
            ->helperText('Until you sign out. Untick this on a computer other people use, and closing the browser signs you out.')
            ->default(true);
    }
}
