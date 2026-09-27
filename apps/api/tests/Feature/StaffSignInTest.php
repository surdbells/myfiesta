<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\TokenAbility;
use App\Filament\Auth\Login;
use App\Mail\EmailChangeConfirm;
use App\Models\AuditLog;
use App\Models\EmailChange;
use App\Models\User;
use App\Notifications\StaffSignInCode;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as FilamentDashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Staff sign in with a password and then a code emailed to them, every time.
 *
 * Driven through the real sign-in page rather than actingAs(), because the
 * thing under test is exactly the part actingAs() skips.
 */
class StaffSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function staff(?PlatformRole $role = PlatformRole::Support, array $attributes = []): User
    {
        return User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * The password step: returns the page, now asking for the code, and the
     * code that was emailed.
     *
     * @return array{0: Testable, 1: string}
     */
    private function passwordStep(User $staff): array
    {
        Notification::fake();

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => $staff->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $code = null;

        Notification::assertSentTo($staff, StaffSignInCode::class, function (StaffSignInCode $sent) use (&$code) {
            $code = $sent->code;

            return true;
        });

        return [$login, $code];
    }

    private function enterCode(Testable $login, string $code): Testable
    {
        return $login
            ->set('data.multiFactor.email_code.code', $code)
            ->call('authenticate');
    }

    private function aCodeThatIsNot(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_a_password_alone_does_not_open_the_admin(): void
    {
        $staff = $this->staff();

        [$login] = $this->passwordStep($staff);

        $login->assertNotSet('userUndertakingMultiFactorAuthentication', null);
        $this->assertGuest();

        $this->get('/admin')->assertRedirect(Filament::getLoginUrl());
    }

    public function test_the_code_is_emailed_to_the_account_at_once_rather_than_queued(): void
    {
        $staff = $this->staff();

        $this->passwordStep($staff);

        Notification::assertSentTo(
            $staff,
            StaffSignInCode::class,
            function (StaffSignInCode $sent, array $channels) use ($staff) {
                // A queued notification is a readable copy of the code in the
                // jobs table; the session only keeps a hash of it.
                return $channels === ['mail']
                    && ! $sent instanceof ShouldQueue
                    && $staff->routeNotificationFor('mail') === $staff->email
                    && preg_match('/^\d{6}$/', $sent->code) === 1;
            },
        );
    }

    public function test_the_email_carries_the_code_but_the_subject_does_not(): void
    {
        $mail = (new StaffSignInCode('482913', 10))->toMail($this->staff());

        $this->assertStringNotContainsString('482913', $mail->subject);
        $this->assertStringContainsString('482913', implode("\n", $mail->introLines));
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $staff = $this->staff();

        [$login, $code] = $this->passwordStep($staff);

        $this->enterCode($login, $this->aCodeThatIsNot($code))
            ->assertHasErrors(['data.multiFactor.email_code.code']);

        $this->assertGuest();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $staff = $this->staff();

        [$login, $code] = $this->passwordStep($staff);

        $this->travel(StaffSignInCode::EXPIRES_MINUTES + 1)->minutes();

        $this->enterCode($login, $code)
            ->assertHasErrors(['data.multiFactor.email_code.code']);

        $this->assertGuest();
    }

    public function test_a_code_works_only_once(): void
    {
        $staff = $this->staff();

        [$login, $code] = $this->passwordStep($staff);
        $this->enterCode($login, $code)->assertHasNoErrors();

        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['email_code'];

        $this->assertFalse($provider->verifyCode($code, $staff), 'A used code was accepted again.');
    }

    public function test_the_right_code_signs_in_on_a_fresh_session_id_and_stays_signed_in(): void
    {
        $staff = $this->staff(PlatformRole::Admin);

        [$login, $code] = $this->passwordStep($staff);

        $before = session()->getId();

        $this->enterCode($login, $code)
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($staff);
        $this->assertNotSame($before, session()->getId(), 'The session id was not rotated at sign-in.');

        // Ticked by default: a remember cookie, for the staff lifetime.
        $remember = Cookie::queued(auth()->guard('web')->getRecallerName());
        $this->assertNotNull($remember, 'No remember cookie was issued.');
        $this->assertEqualsWithDelta(
            now()->addMinutes((int) config('session.staff_remember'))->getTimestamp(),
            $remember->getExpiresTime(),
            300,
        );

        $this->assertNotNull($staff->fresh()->last_login_at);

        $entry = AuditLog::where('action', 'staff.signed_in')->sole();
        $this->assertSame($staff->id, $entry->actor_id);
        $this->assertTrue($entry->metadata['kept_signed_in']);

        // And the code is not asked for again while the session lives.
        $this->get('/admin/staff')->assertOk();
    }

    public function test_keep_me_signed_in_is_ticked_by_default(): void
    {
        Livewire::test(Login::class)->assertSet('data.remember', true);
    }

    public function test_unticking_keep_me_signed_in_issues_no_remember_cookie(): void
    {
        $staff = $this->staff();

        Notification::fake();

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => $staff->email, 'password' => 'password', 'remember' => false])
            ->call('authenticate');

        $code = null;
        Notification::assertSentTo($staff, StaffSignInCode::class, function (StaffSignInCode $sent) use (&$code) {
            $code = $sent->code;

            return true;
        });

        $this->enterCode($login, $code)->assertHasNoErrors();

        $this->assertAuthenticatedAs($staff);
        $this->assertNull(Cookie::queued(auth()->guard('web')->getRecallerName()));
    }

    /** Both steps, with "keep me signed in" as given. */
    private function signIn(User $staff, bool $keep): void
    {
        Notification::fake();

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => $staff->email, 'password' => 'password', 'remember' => $keep])
            ->call('authenticate');

        $code = null;
        Notification::assertSentTo($staff, StaffSignInCode::class, function (StaffSignInCode $sent) use (&$code) {
            $code = $sent->code;

            return true;
        });

        $this->enterCode($login, $code)->assertHasNoErrors();
        $this->assertAuthenticatedAs($staff);
    }

    private function sessionCookieExpiry(TestResponse $response): int
    {
        $cookie = $response->getCookie(config('session.cookie'), decrypt: false);

        $this->assertNotNull($cookie, 'No session cookie on the response.');

        return $cookie->getExpiresTime();
    }

    public function test_unticked_the_session_ends_when_the_browser_closes(): void
    {
        $staff = $this->staff(PlatformRole::Admin);

        $this->signIn($staff, keep: false);

        // The sign-in's own response, and every admin page after it.
        $this->assertTrue(config('session.expire_on_close'));
        $this->assertSame(0, $this->sessionCookieExpiry($this->get('/admin/staff')->assertOk()), 'An unticked sign-in left a session cookie that outlives the browser.');
    }

    public function test_kept_signed_in_the_session_outlives_the_browser(): void
    {
        $staff = $this->staff(PlatformRole::Admin);

        $this->signIn($staff, keep: true);

        $this->assertFalse(config('session.expire_on_close'));
        $this->assertEqualsWithDelta(
            now()->addMinutes((int) config('session.staff_lifetime'))->getTimestamp(),
            $this->sessionCookieExpiry($this->get('/admin/staff')->assertOk()),
            120,
        );
    }

    // ---- The address the codes go to ------------------------------------

    public function test_a_staff_password_alone_cannot_move_where_the_codes_go(): void
    {
        Mail::fake();

        $staff = $this->staff(PlatformRole::Admin, ['email' => 'ada@example.com']);

        // What the password gets anybody outside the admin: an API token.
        Sanctum::actingAs($staff, [TokenAbility::Attendee->value]);

        $this->postJson('/api/auth/email', ['email' => 'somebody-else@example.net', 'current_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'staff access']);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailChange::count());
        $this->assertSame('ada@example.com', $staff->fresh()->email);
        $this->assertSame(PlatformRole::Admin, $staff->fresh()->platform_role);
    }

    public function test_an_address_moved_any_other_way_takes_the_staff_role_with_it(): void
    {
        $staff = $this->staff(PlatformRole::Finance, ['email' => 'ada@example.com']);
        $token = $staff->getRememberToken();

        $staff->forceFill(['email' => 'somebody-else@example.net', 'email_verified_at' => now()])->save();

        $staff->refresh();
        $this->assertNull($staff->platform_role);
        $this->assertNotSame($token, $staff->getRememberToken(), 'A remembered browser outlived the address.');

        $entry = AuditLog::where('action', 'staff.revoked')->sole();
        $this->assertSame($staff->id, $entry->subject_id);
        $this->assertNull($entry->actor_id);
        $this->assertSame(['role' => 'finance', 'reason' => 'address_changed', 'via' => 'address_change'], $entry->metadata);

        // And the new address is sent no code.
        Notification::fake();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'somebody-else@example.net', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_the_same_address_with_different_capitals_is_not_a_move(): void
    {
        $staff = $this->staff(PlatformRole::Support, ['email' => 'Ada@Example.com']);

        $staff->forceFill(['email' => 'ada@example.com'])->save();

        $this->assertSame(PlatformRole::Support, $staff->fresh()->platform_role);
        $this->assertSame(0, AuditLog::where('action', 'staff.revoked')->count());
    }

    public function test_a_move_asked_for_before_the_role_was_granted_cannot_complete_after(): void
    {
        Mail::fake();

        $person = $this->staff(null, ['email' => 'ada@example.com']);

        Sanctum::actingAs($person, [TokenAbility::Attendee->value]);
        $this->postJson('/api/auth/email', ['email' => 'somebody-else@example.net', 'current_password' => 'password'])
            ->assertAccepted();

        $link = null;
        Mail::assertSent(EmailChangeConfirm::class, function (EmailChangeConfirm $mail) use (&$link) {
            $link = $mail->token;

            return $mail->hasTo('somebody-else@example.net');
        });

        $this->artisan('staff:grant', ['email' => 'ada@example.com', 'role' => 'admin'])->assertSuccessful();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/email/confirm', ['token' => $link])
            ->assertUnprocessable();

        $person->refresh();
        $this->assertSame('ada@example.com', $person->email);
        $this->assertSame(PlatformRole::Admin, $person->platform_role);
    }

    public function test_somebody_who_is_not_staff_is_refused_before_any_code_is_sent(): void
    {
        Notification::fake();

        $buyer = $this->staff(null);

        Livewire::test(Login::class)
            ->fillForm(['email' => $buyer->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email'])
            ->assertSet('userUndertakingMultiFactorAuthentication', null);

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_staff_whose_address_is_unverified_are_sent_no_code(): void
    {
        Notification::fake();

        $staff = $this->staff(PlatformRole::Finance, ['email_verified_at' => null]);

        Livewire::test(Login::class)
            ->fillForm(['email' => $staff->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_wrong_password_sends_no_code(): void
    {
        Notification::fake();

        $staff = $this->staff();

        Livewire::test(Login::class)
            ->fillForm(['email' => $staff->email, 'password' => 'not-the-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        Notification::assertNothingSent();
    }

    public function test_codes_cannot_be_switched_off(): void
    {
        $staff = $this->staff();

        $this->assertTrue($staff->hasEmailAuthentication());
        $this->assertTrue(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());

        $this->expectException(LogicException::class);
        $staff->toggleEmailAuthentication(false);
    }

    public function test_signing_in_lands_on_the_platform_dashboard_not_filaments_welcome_screen(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertNotContains(FilamentDashboard::class, $panel->getPages());
        $this->assertNotContains(AccountWidget::class, $panel->getWidgets());
        $this->assertNotContains(FilamentInfoWidget::class, $panel->getWidgets());

        $home = Route::getRoutes()->getByName('filament.admin.pages.dashboard');
        $this->assertNotNull($home, 'The admin has no home page.');
        $this->assertNotSame(FilamentDashboard::class, $home->getControllerClass());
    }
}
