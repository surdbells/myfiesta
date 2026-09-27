<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\User;
use App\Services\Accounts\Terms;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\WhileImpersonating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An account that was never asked about the terms, asked from inside.
 *
 * Signing up asks and so does a checkout that knows its buyer. An organizer
 * whose account is older than that box, or came over from the previous
 * platform, or who never buys a ticket, was never asked at all — the console
 * and the phone ask them through GET and POST /api/auth/terms.
 */
class AccountTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['terms.version' => '2026-09-27']);
    }

    private function organizer(array $attributes = []): User
    {
        $user = User::factory()->create(['email' => 'ada@example.com', ...$attributes]);
        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);
    }

    public function test_an_account_that_never_agreed_is_told_so(): void
    {
        $this->signIn($this->organizer());

        $this->getJson('/api/auth/terms')
            ->assertOk()
            ->assertExactJson([
                'current' => '2026-09-27',
                'accepted' => false,
                'accepted_version' => null,
                'accepted_at' => null,
            ]);
    }

    public function test_agreeing_keeps_the_version_in_force_and_the_moment(): void
    {
        $user = $this->organizer();
        $this->signIn($user);
        $this->freezeSecond();

        $this->postJson('/api/auth/terms', ['accept_terms' => true])
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('accepted_version', '2026-09-27')
            ->assertJsonPath('accepted_at', now()->toIso8601String());

        $user->refresh();
        $this->assertSame('2026-09-27', $user->terms_version);
        $this->assertTrue($user->terms_accepted_at->equalTo(now()));
        $this->assertTrue(app(Terms::class)->acceptedBy($user));

        $this->getJson('/api/auth/terms')->assertOk()->assertJsonPath('accepted', true);
    }

    public function test_without_the_box_ticked_it_is_refused_in_plain_words_and_nothing_is_kept(): void
    {
        $user = $this->organizer();
        $this->signIn($user);

        foreach ([[], ['accept_terms' => false]] as $body) {
            $this->postJson('/api/auth/terms', $body)
                ->assertUnprocessable()
                ->assertJsonPath('errors.accept_terms.0', Terms::REFUSAL);
        }

        $this->assertNull($user->fresh()->terms_version);
        $this->assertNull($user->fresh()->terms_accepted_at);
    }

    public function test_agreeing_twice_keeps_the_first_moment(): void
    {
        $user = $this->organizer();
        $this->signIn($user);
        $this->freezeSecond();
        $first = now();

        $this->postJson('/api/auth/terms', ['accept_terms' => true])->assertOk();

        // A second tab catching up is not a second agreement.
        $this->travel(3)->days();
        $this->postJson('/api/auth/terms', ['accept_terms' => true])
            ->assertOk()
            ->assertJsonPath('accepted_at', $first->toIso8601String());

        $this->assertTrue($user->fresh()->terms_accepted_at->equalTo($first));
    }

    public function test_new_words_are_asked_about_and_agreeing_moves_the_account_to_them(): void
    {
        $user = $this->organizer([
            'terms_version' => '2026-01-01',
            'terms_accepted_at' => now()->subMonths(6),
        ]);
        $this->signIn($user);

        // What was agreed to is still said, so the prompt can say the words changed.
        $this->getJson('/api/auth/terms')
            ->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('accepted_version', '2026-01-01');

        $this->postJson('/api/auth/terms', ['accept_terms' => true])
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('accepted_version', '2026-09-27');

        $this->assertSame('2026-09-27', $user->fresh()->terms_version);
    }

    public function test_nobody_signed_in_is_asked_nothing(): void
    {
        $this->getJson('/api/auth/terms')->assertUnauthorized();
        $this->postJson('/api/auth/terms', ['accept_terms' => true])->assertUnauthorized();
    }

    public function test_a_door_pass_cannot_agree_for_the_member_who_issued_it(): void
    {
        $user = $this->organizer();
        // A real token: what the scope check reads is the abilities stored on it.
        $token = $user->createToken('Front gate', [TokenAbility::doorFor((string) Str::uuid())])->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/terms')->assertForbidden();
        $this->withToken($token)->postJson('/api/auth/terms', ['accept_terms' => true])->assertForbidden();

        $this->assertNull($user->fresh()->terms_version);
    }

    public function test_staff_acting_as_an_organization_cannot_agree_for_anybody(): void
    {
        $this->organizer();
        $org = Organization::sole();
        $staff = User::factory()->create([
            'name' => 'Sade Support',
            'platform_role' => PlatformRole::Support,
            'email_verified_at' => now(),
        ]);

        $code = app(Impersonation::class)->start($org, $staff, 'Ticket #4411: tiers look wrong')['code'];
        $token = $this->postJson('/api/impersonation/exchange', ['code' => $code])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        foreach ([['GET', []], ['POST', ['accept_terms' => true]]] as [$method, $body]) {
            $this->withToken($token)
                ->withHeader('X-Organization', $org->id)
                ->json($method, '/api/auth/terms', $body)
                ->assertForbidden()
                ->assertJsonPath('message', WhileImpersonating::OWN_ACCOUNT);
        }

        // Not the staff member's account, and not the organizer's either.
        $this->assertNull($staff->fresh()->terms_version);
        $this->assertNull(User::where('email', 'ada@example.com')->sole()->terms_version);
    }
}
