<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Http\Middleware\AuthenticateStaff;
use App\Http\Middleware\KeepStaffSignedIn;
use App\Models\User;
use App\Support\Session\StaffSessionHandler;
use App\Support\Session\StaffSignIn;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Staff stay signed in until they sign out — and not a moment after they
 * sign out, change their password, or stop being staff.
 *
 * A remembered browser is simulated the way one arrives: no session, just the
 * remember cookie. forgetGuards() between requests stands in for the next
 * request being a new PHP process, which the test client otherwise is not.
 */
class StaffSessionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(PlatformRole $role = PlatformRole::Admin): User
    {
        return User::factory()->create([
            'platform_role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    /** The cookie "keep me signed in" leaves in the browser. */
    private function rememberedBrowser(User $user): static
    {
        $guard = auth()->guard('web');

        return $this->withCookie(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
        );
    }

    private function nextRequestIsANewProcess(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function sessionCookie(TestResponse $response): Cookie
    {
        $cookie = $response->getCookie(config('session.cookie'), decrypt: false);

        $this->assertNotNull($cookie, 'No session cookie on the response.');

        return $cookie;
    }

    public function test_a_remembered_browser_comes_back_without_being_asked_for_a_code(): void
    {
        $staff = $this->staff();

        $response = $this->rememberedBrowser($staff)
            ->get('/admin/staff')
            ->assertOk();

        $this->assertAuthenticatedAs($staff);

        // Only somebody who ticked the box has the cookie, so the session it
        // starts is kept too — on this request and the ones after it, which
        // come in on the session rather than the cookie.
        $staffLifetime = now()->addMinutes((int) config('session.staff_lifetime'))->getTimestamp();
        $this->assertEqualsWithDelta($staffLifetime, $this->sessionCookie($response)->getExpiresTime(), 120);

        $this->nextRequestIsANewProcess();
        $this->assertEqualsWithDelta($staffLifetime, $this->sessionCookie($this->get('/admin/staff')->assertOk())->getExpiresTime(), 120);
        $this->assertAuthenticatedAs($staff);
    }

    public function test_a_session_signed_in_without_the_code_is_signed_out(): void
    {
        $staff = $this->staff();
        $token = $staff->getRememberToken();

        // What the admin's sign-in left in a session before it asked for a
        // code — the account, and nothing to say a code was passed. Any other
        // way into the web guard would look the same.
        $guard = auth()->guard('web');

        $this->withSession([$guard->getName() => $staff->getAuthIdentifier()])
            ->get('/admin/staff')
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertGuest();
        $this->assertNotSame($token, $staff->fresh()->getRememberToken(), 'A remember cookie from that sign-in still works.');

        // Signed out, not merely turned away once.
        $this->nextRequestIsANewProcess();
        $this->get('/admin/staff')->assertRedirect(Filament::getLoginUrl());
    }

    public function test_remember_cookies_and_sessions_from_before_codes_stop_working(): void
    {
        config(['session.driver' => 'database']);

        $staff = $this->staff();
        $buyer = User::factory()->create();
        $buyerToken = $buyer->getRememberToken();

        $session = fn (?string $userId) => [
            'id' => Str::random(40),
            'user_id' => $userId,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ];
        DB::table('sessions')->insert([$session($staff->id), $session($buyer->id), $session(null)]);

        (require database_path('migrations/2026_09_26_010600_staff_sign_in_again_with_a_code.php'))->up();

        $this->assertNull(DB::table('users')->where('id', $staff->id)->value('remember_token'));
        $this->assertSame($buyerToken, $buyer->fresh()->getRememberToken(), 'Somebody who is not staff was signed out.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->assertSame(2, DB::table('sessions')->count());

        // The cookie made from the old token ($staff still holds it) no
        // longer signs anybody in.
        $this->rememberedBrowser($staff)
            ->get('/admin/staff')
            ->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_signing_out_ends_the_remembered_session(): void
    {
        $staff = $this->staff();
        $token = $staff->getRememberToken();

        $this->rememberedBrowser($staff)->get('/admin/staff')->assertOk();

        $this->post('/admin/logout')->assertRedirect();

        $this->assertGuest();
        $this->assertNotSame($token, $staff->fresh()->getRememberToken(), 'Signing out left the remember token as it was.');

        // A copy of the cookie taken before signing out no longer works.
        $this->nextRequestIsANewProcess();
        $this->get('/admin/staff')->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_a_new_password_ends_remembered_sessions(): void
    {
        $staff = $this->staff();
        $token = $staff->getRememberToken();

        $this->rememberedBrowser($staff);

        $staff->forceFill(['password' => 'a-completely-different-passphrase'])->save();

        $this->assertNotSame($token, $staff->fresh()->getRememberToken());

        $this->nextRequestIsANewProcess();
        $this->get('/admin/staff')->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_saving_without_changing_the_password_leaves_sessions_alone(): void
    {
        $staff = $this->staff();
        $token = $staff->getRememberToken();

        $staff->forceFill(['name' => 'A New Name'])->save();

        $this->assertSame($token, $staff->fresh()->getRememberToken());
    }

    public function test_losing_the_role_signs_the_person_out_on_their_next_request(): void
    {
        $staff = $this->staff(PlatformRole::Admin);
        $this->staff(PlatformRole::Admin);
        $token = $staff->getRememberToken();

        $this->rememberedBrowser($staff)->get('/admin/staff')->assertOk();

        // Removed behind the admin's back — the per-request check is what
        // matters here, not the Staff screen.
        DB::table('users')->where('id', $staff->id)->update(['platform_role' => null]);

        $this->nextRequestIsANewProcess();
        $this->get('/admin/staff')->assertForbidden();

        $this->assertGuest();
        $this->assertNotSame($token, $staff->fresh()->getRememberToken(), 'The remember cookie outlived the role.');

        // Signed out, not merely refused: the next visit is the sign-in page,
        // even after the role is given back.
        DB::table('users')->where('id', $staff->id)->update(['platform_role' => 'admin']);
        $this->nextRequestIsANewProcess();
        $this->get('/admin/staff')->assertRedirect(Filament::getLoginUrl());
    }

    public function test_the_role_check_also_guards_livewire_requests(): void
    {
        $persistent = Livewire::getPersistentMiddleware();

        $this->assertContains(AuthenticateStaff::class, $persistent);
    }

    public function test_the_admin_keeps_sessions_for_the_staff_lifetime_but_only_kept_ones_outlive_the_browser(): void
    {
        $cookie = $this->sessionCookie($this->get('/admin/login')->assertOk());

        // Idle for up to the staff lifetime on the server...
        $this->assertSame((int) config('session.staff_lifetime'), (int) config('session.lifetime'));

        // ...but nobody here has asked to be kept signed in, so the cookie
        // goes when the browser does. A kept sign-in's cookie is the long one:
        // see the remembered browser above, and StaffSignInTest.
        $this->assertSame(0, $cookie->getExpiresTime());
    }

    public function test_the_rest_of_the_site_keeps_the_ordinary_lifetime(): void
    {
        $ordinary = (int) config('session.lifetime');

        $cookie = $this->sessionCookie($this->get('/'));

        $this->assertEqualsWithDelta(now()->addMinutes($ordinary)->getTimestamp(), $cookie->getExpiresTime(), 120);
        $this->assertLessThan((int) config('session.staff_lifetime'), $ordinary);
    }

    public function test_the_admins_livewire_requests_keep_the_staff_lifetime(): void
    {
        $route = Route::getRoutes()->getByName('admin.livewire.update');

        $this->assertNotNull($route, 'The admin does not own the Livewire update route.');
        $this->assertSame([KeepStaffSignedIn::class, 'web'], $route->middleware());
        $this->assertSame('/livewire/update', app('livewire')->getUpdateUri());

        // Nothing to update is a 404, but the session it answers with is
        // still kept for the staff lifetime. Nobody is signed in on it, so its
        // cookie ends with the browser; a signed-in admin component's requests
        // also run AuthenticateStaff (persistent, above), which keeps the
        // cookie of a sign-in that asked to be kept.
        $cookie = $this->sessionCookie($this->post('/livewire/update')->assertNotFound());

        $this->assertSame((int) config('session.staff_lifetime'), (int) config('session.lifetime'));
        $this->assertSame(0, $cookie->getExpiresTime());
    }

    /**
     * A real click in the admin: the Staff page's own Livewire snapshot,
     * posted back to the update endpoint the way the browser does.
     */
    private function clickOnTheStaffPage(): TestResponse
    {
        $html = $this->get('/admin/staff')->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        $snapshot = collect($matches[1])
            ->map(fn (string $attribute) => html_entity_decode($attribute, ENT_QUOTES))
            ->first(fn (string $json) => str_contains(json_decode($json, true)['memo']['name'] ?? '', 'list-staff'));

        $this->assertNotNull($snapshot, 'The Staff page has no Livewire component to click on.');

        $this->nextRequestIsANewProcess();

        return $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', [
            'components' => [['snapshot' => $snapshot, 'updates' => ['tableSearch' => 'a'], 'calls' => []]],
        ])->assertOk();
    }

    public function test_a_kept_sign_ins_clicks_keep_the_long_cookie(): void
    {
        $this->rememberedBrowser($this->staff());

        $this->assertEqualsWithDelta(
            now()->addMinutes((int) config('session.staff_lifetime'))->getTimestamp(),
            $this->sessionCookie($this->clickOnTheStaffPage())->getExpiresTime(),
            120,
        );
    }

    public function test_an_unticked_sign_ins_clicks_end_with_the_browser(): void
    {
        $staff = $this->staff();

        // Signed in through the code, without "keep me signed in".
        $this->withSession([auth()->guard('web')->getName() => $staff->getAuthIdentifier()]);
        StaffSignIn::record(app('session')->driver(), $staff, keepSignedIn: false);

        $this->assertSame(0, $this->sessionCookie($this->clickOnTheStaffPage())->getExpiresTime());
    }

    public function test_the_database_driver_keeps_idle_staff_sessions(): void
    {
        config(['session.driver' => 'database']);

        $handler = app('session')->driver('database')->getHandler();
        $this->assertInstanceOf(StaffSessionHandler::class, $handler);

        $now = now()->getTimestamp();
        $row = fn (?string $userId, int $idleSeconds) => [
            'id' => Str::random(40),
            'user_id' => $userId,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => $now - $idleSeconds,
        ];

        $staffId = (string) Str::uuid();
        $rows = [
            'anonymous, idle three hours' => $row(null, 3 * 3600),
            'anonymous, idle an hour' => $row(null, 3600),
            'staff, idle three hours' => $row($staffId, 3 * 3600),
            'staff, idle past the staff lifetime' => $row($staffId, ((int) config('session.staff_lifetime') + 60) * 60),
        ];
        DB::table('sessions')->insert(array_values($rows));

        // What a webhook post would run, with the ordinary two hours.
        $handler->gc(7200);

        $left = DB::table('sessions')->pluck('id')->all();

        $this->assertNotContains($rows['anonymous, idle three hours']['id'], $left);
        $this->assertContains($rows['anonymous, idle an hour']['id'], $left);
        $this->assertContains($rows['staff, idle three hours']['id'], $left, 'An idle staff session was collected after the ordinary lifetime.');
        $this->assertNotContains($rows['staff, idle past the staff lifetime']['id'], $left);

        // And reading: the staff session is still a session, the idle
        // anonymous one past two hours would not be.
        $reader = fn () => new StaffSessionHandler(DB::connection(), 'sessions', 120, (int) config('session.staff_lifetime'));
        $this->assertSame('a:0:{}', $reader()->read($rows['staff, idle three hours']['id']));

        DB::table('sessions')->insert($stale = $row(null, 3 * 3600));
        $this->assertSame('', $reader()->read($stale['id']));
    }
}
