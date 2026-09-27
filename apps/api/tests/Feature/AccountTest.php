<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FinishesSignUps;
use Tests\TestCase;

/**
 * Getting an account, and getting back into one.
 *
 * Until this existed nobody could join the platform at all — every account was
 * inserted by hand. The tests that matter most here are the ones about what the
 * endpoints refuse to tell a stranger: on a platform whose organizers are named
 * venues and promoters, "is this address registered?" is competitive
 * intelligence, and both registration and password reset leak it by default.
 */
class AccountTest extends TestCase
{
    use FinishesSignUps, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        RateLimiter::clear('register:127.0.0.1');
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'password' => 'correct horse 7',
            'password_confirmation' => 'correct horse 7',
            'organization' => 'Lagos Nights',
        ], $overrides);
    }

    // --- signing up ----------------------------------------------------------

    public function test_signing_up_creates_a_person_and_their_organization(): void
    {
        $this->postJson('/api/auth/register', $this->registration())->assertStatus(202);

        // Nothing until the address is proved.
        $this->assertSame(0, User::count());
        $this->assertSame(0, Organization::count());

        $this->post($this->relative($this->signUpLink('ada@example.com')), ['password' => 'correct horse 7'])->assertOk();

        $user = User::sole();
        $this->assertSame('ada@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);

        $organization = Organization::sole();
        $this->assertSame('Lagos Nights', $organization->name);
        // Owner, not manager. Nobody else can hand that over.
        $this->assertTrue($user->hasRoleIn($organization, Role::Owner));
    }

    public function test_the_password_is_stored_hashed_and_actually_works(): void
    {
        $this->signUpAndConfirm($this->registration());

        $user = User::first();

        $this->assertNotSame('correct horse 7', $user->password);
        $this->assertTrue(Hash::check('correct horse 7', $user->password));

        // Hashed once, when the form was posted, and carried to the account as
        // it was. Hashing again on the way stores a hash of a hash, and every
        // sign-in fails from then on — visible only at the next sign-in.
        $this->signIn('ada@example.com', 'correct horse 7');
    }

    public function test_the_new_account_signs_in_straight_to_the_console(): void
    {
        $this->signUpAndConfirm($this->registration());

        $token = $this->signIn('ada@example.com', 'correct horse 7', 'organizer-console')['token'];

        $this->withToken($token)->getJson('/api/organizer/events')->assertOk();
    }

    public function test_an_address_already_in_use_is_not_reported_as_such(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/auth/register', $this->registration());

        // Not a 422 naming the field. "That email is taken" is a way to test
        // whether a named venue holds an account here.
        $response->assertStatus(202);
        $response->assertJsonMissingPath('errors');
        $response->assertJsonMissingPath('token');
        $this->assertStringNotContainsString('taken', $response->getContent());

        // And no second account was made.
        $this->assertSame(1, User::count());
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->postJson('/api/auth/register', $this->registration([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_mistyping_the_password_is_caught_before_the_account_exists(): void
    {
        $this->postJson('/api/auth/register', $this->registration([
            'password_confirmation' => 'something else entirely',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The two passwords do not match.');

        $this->assertSame(0, User::count());
    }

    public function test_two_organizations_with_the_same_name_get_different_slugs(): void
    {
        $this->signUpAndConfirm($this->registration());
        RateLimiter::clear('register:127.0.0.1');
        $this->signUpAndConfirm($this->registration([
            'email' => 'chidi@example.com',
        ]));

        // A slug is a public URL. Two organizations cannot share one.
        $this->assertSame(2, Organization::distinct('slug')->count('slug'));
    }

    // --- forgetting a password ----------------------------------------------

    public function test_a_reset_link_is_sent_to_a_real_account(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_same_answer_comes_back_for_an_unknown_address(): void
    {
        $known = User::factory()->create(['email' => 'ada@example.com']);

        $a = $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com']);
        $b = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        // Byte for byte. A different message, or a different status, is enough
        // to enumerate the whole users table one address at a time.
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->getContent(), $b->getContent());

        Notification::assertSentTo($known, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_the_reset_link_points_at_the_console_not_at_the_api(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com']);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // Laravel's default builds a link to a Blade route that does not
            // exist here, so the email would arrive pointing at a 404 — and
            // nobody finds out until somebody is genuinely locked out.
            return str_starts_with($url, config('app.console_url').'/reset-password')
                && str_contains($url, 'token=');
        });
    }

    public function test_a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
            'password_confirmation' => 'a whole new one 9',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
        ])->assertOk();
    }

    public function test_resetting_signs_out_every_existing_session(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $user->createToken('old phone', [TokenAbility::Attendee->value]);
        $user->createToken('old laptop', [TokenAbility::Attendee->value]);

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
            'password_confirmation' => 'a whole new one 9',
        ])->assertOk();

        // A reset is what somebody does after losing a phone. Leaving the old
        // sessions alive means it protected them from nothing.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_stale_token_is_refused_with_something_actionable(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
            'password_confirmation' => 'a whole new one 9',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That reset link has expired. Ask for a new one.');
    }

    // --- managing an account -------------------------------------------------

    public function test_a_profile_can_be_updated(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        Sanctum::actingAs($user, [TokenAbility::Attendee->value]);

        $this->patchJson('/api/auth/profile', [
            'name' => 'Ada Okafor',
            'timezone' => 'Africa/Lagos',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Ada Okafor')
            ->assertJsonPath('timezone', 'Africa/Lagos');
    }

    public function test_who_am_i_includes_what_the_profile_form_edits(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'phone' => '+234 801 234 5678',
            'timezone' => 'Africa/Lagos',
        ]);
        Sanctum::actingAs($user, [TokenAbility::Attendee->value]);

        // Without the number, the phone's details form opened with the box
        // empty — and saving it as it looked threw the number away.
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('name', 'Ada Okafor')
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonPath('phone', '+234 801 234 5678')
            ->assertJsonPath('timezone', 'Africa/Lagos')
            ->assertJsonPath('organizations', []);
    }

    public function test_a_phone_number_can_be_taken_off_the_account(): void
    {
        $user = User::factory()->create(['phone' => '+1 416 555 0100']);
        Sanctum::actingAs($user, [TokenAbility::Attendee->value]);

        // Emptying the box sends null, and null means "none", not "unchanged".
        $this->patchJson('/api/auth/profile', ['phone' => null])
            ->assertOk()
            ->assertJsonPath('phone', null);

        $this->assertNull($user->fresh()->phone);
        $this->getJson('/api/auth/me')->assertJsonPath('phone', null);
    }

    public function test_a_profile_saved_without_a_phone_keeps_the_one_it_has(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'phone' => '+1 416 555 0100']);
        Sanctum::actingAs($user, [TokenAbility::Attendee->value]);

        // Only a field that is sent changes. Renaming yourself must not cost
        // you the number the team reaches you on.
        $this->patchJson('/api/auth/profile', ['name' => 'Ada Okafor'])->assertOk();

        $this->assertSame('+1 416 555 0100', $user->fresh()->phone);
    }

    public function test_the_email_address_cannot_be_changed_from_the_profile(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user, [TokenAbility::Attendee->value]);

        $this->patchJson('/api/auth/profile', ['email' => 'someone-else@example.com'])
            ->assertOk();

        // Changing the address an account is reached at, without confirming at
        // the new address, is how an account is taken over from a borrowed
        // laptop.
        $this->assertSame('ada@example.com', $user->fresh()->email);
    }

    public function test_changing_a_password_needs_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'the old one 12']);
        Sanctum::actingAs($user, [TokenAbility::Attendee->value]);

        $this->postJson('/api/auth/password', [
            'current_password' => 'a guess',
            'password' => 'the new one 12',
            'password_confirmation' => 'the new one 12',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_changing_a_password_signs_out_other_devices_but_not_this_one(): void
    {
        $user = User::factory()->create(['password' => 'the old one 12']);
        $user->createToken('old phone', [TokenAbility::Attendee->value]);

        // A real token, so currentAccessToken() has an id to compare against.
        $current = $user->createToken('this laptop', [TokenAbility::Attendee->value]);

        $this->withToken($current->plainTextToken)
            ->postJson('/api/auth/password', [
                'current_password' => 'the old one 12',
                'password' => 'the new one 12',
                'password_confirmation' => 'the new one 12',
            ])
            ->assertOk();

        // Signing somebody out of the laptop they are typing on is a surprise;
        // leaving the lost phone signed in is a security hole.
        $remaining = $user->tokens()->pluck('name')->all();
        $this->assertSame(['this laptop'], $remaining);
    }

    public function test_signing_up_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', $this->registration([
                'email' => "someone{$i}@example.com",
                'organization' => 'Org '.Str::random(5),
            ]));
        }

        $this->postJson('/api/auth/register', $this->registration([
            'email' => 'one-too-many@example.com',
        ]))->assertStatus(429);
    }
}
