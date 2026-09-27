<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\VerifyEmailAddress;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Accounts\EmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FinishesSignUps;
use Tests\TestCase;

/**
 * An address somebody has proved they read, before the account acts in public
 * or moves money.
 *
 * Until sign-ups waited for their link, nothing here ever checked an address:
 * anybody could open an account as anybody, name an events page after them,
 * put a night on sale and point the takings at their own bank.
 */
class EmailVerificationTest extends TestCase
{
    use FinishesSignUps, RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
        $this->event = Event::factory()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'status' => 'draft',
            'starts_at' => now()->addWeeks(3),
        ]);
        TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 3000,
            'quantity_available' => 100,
            'status' => 'on_sale',
        ]);
    }

    private function owner(bool $verified): User
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'correct horse 7',
            'email_verified_at' => $verified ? now() : null,
        ]);

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        $this->actAs($user);

        return $user;
    }

    /** Signed in as the account as it is now, not as it was loaded. */
    private function actAs(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function publish(string $status = 'published')
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => $status]);
    }

    /** @return array<string, string> */
    private function bankDetails(): array
    {
        return [
            'rail' => 'bank_transfer',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'Royal Bank',
            'account_number' => '1234567',
            'transit_number' => '00012',
            'institution_number' => '003',
        ];
    }

    /** The link in the most recent verification email to this address. */
    private function verificationLink(string $email): string
    {
        $url = null;

        Mail::assertSent(VerifyEmailAddress::class, function (VerifyEmailAddress $mail) use ($email, &$url) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $url = $mail->url;

            return true;
        });

        return $this->relative($url);
    }

    // --- what waits ------------------------------------------------------------

    public function test_an_unproved_address_cannot_put_a_night_on_sale(): void
    {
        $this->owner(verified: false);

        $this->publish()
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $this->assertSame('draft', $this->event->fresh()->status);

        // The refusal sends the link, so the next step is in the inbox rather
        // than in a settings screen somebody has to find.
        Mail::assertSent(VerifyEmailAddress::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_taking_a_night_off_sale_never_waits(): void
    {
        $this->event->update(['status' => 'published']);
        $this->owner(verified: false);

        // An account that cannot prove its address must still be able to
        // stop selling.
        $this->publish('draft')->assertOk();

        $this->assertSame('draft', $this->event->fresh()->status);
    }

    public function test_where_the_money_goes_and_asking_for_it_wait(): void
    {
        $this->owner(verified: false);

        $this->putJson('/api/organizer/payout-details', $this->bankDetails())
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $this->postJson('/api/organizer/payouts/requests', ['amount' => 1000])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $this->assertDatabaseMissing('organization_payout_details', ['organization_id' => $this->org->id]);
    }

    public function test_a_proved_address_does_all_of_it(): void
    {
        $this->owner(verified: true);

        $this->publish()->assertOk();
        $this->putJson('/api/organizer/payout-details', $this->bankDetails())->assertOk();

        Mail::assertNotSent(VerifyEmailAddress::class);
    }

    public function test_only_those_three_wait_and_buying_is_not_one_of_them(): void
    {
        $waiting = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'verified.email')))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->sort()
            ->values()
            ->all();

        // Everything else works unproved; buying a ticket as a guest needs no
        // account at all, let alone a proved one.
        $this->assertSame([
            'POST api/organizer/events/{event}/publish',
            'POST api/organizer/payouts/requests',
            'PUT api/organizer/payout-details',
        ], $waiting);
    }

    // --- proving it ------------------------------------------------------------

    public function test_the_link_proves_the_address_with_the_accounts_password(): void
    {
        $user = $this->owner(verified: false);

        $this->postJson('/api/auth/email/verification')
            ->assertStatus(202)
            ->assertJsonPath('verified', false);

        $link = $this->verificationLink('ada@example.com');

        // A mail scanner following the link proves nothing.
        $this->get($link)->assertOk()->assertSee('Confirm this address');
        $this->assertNull($user->fresh()->email_verified_at);

        // Nor does whoever reads the inbox without knowing the account's
        // password: an account made under somebody else's address before
        // sign-ups waited for their link must not be proved by its victim.
        $this->post($link, ['password' => 'a guess 12345'])->assertStatus(422);
        $this->assertNull($user->fresh()->email_verified_at);

        $this->post($link, ['password' => 'correct horse 7'])->assertOk()->assertSee('Address confirmed');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->actAs($user);
        $this->publish()->assertOk();
    }

    public function test_a_link_for_an_address_the_account_has_left_is_dead(): void
    {
        $user = $this->owner(verified: false);

        $this->postJson('/api/auth/email/verification')->assertStatus(202);
        $link = $this->verificationLink('ada@example.com');

        $user->forceFill(['email' => 'ada.new@example.com'])->save();

        $this->get($link)->assertStatus(410);
        $this->post($link, ['password' => 'correct horse 7'])->assertStatus(410);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_or_altered_link_is_dead(): void
    {
        $user = $this->owner(verified: false);

        $this->postJson('/api/auth/email/verification')->assertStatus(202);
        $link = $this->verificationLink('ada@example.com');

        // Pointed at another account: the signature covers the id.
        $other = User::factory()->unverified()->create(['password' => 'correct horse 7']);
        $this->post(str_replace($user->id, $other->id, $link), ['password' => 'correct horse 7'])->assertStatus(410);

        $this->travel(EmailVerification::EXPIRES_HOURS + 1)->hours();
        $this->post($link, ['password' => 'correct horse 7'])->assertStatus(410);

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_asking_again_is_limited_per_account(): void
    {
        $this->owner(verified: false);

        for ($i = 0; $i < EmailVerification::PER_HOUR; $i++) {
            $this->postJson('/api/auth/email/verification')->assertStatus(202);
        }

        $this->postJson('/api/auth/email/verification')->assertStatus(429);

        // A refused publish draws on the same allowance, so neither the button
        // nor the refusal can be used to fill somebody's inbox.
        $this->publish()->assertForbidden();

        Mail::assertSent(VerifyEmailAddress::class, EmailVerification::PER_HOUR);
    }

    public function test_the_account_says_whether_its_address_is_proved(): void
    {
        $user = $this->owner(verified: false);

        // So the console and the phone can say so before somebody presses
        // Publish, rather than learning it from the refusal.
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('email_verified', false);

        $this->postJson('/api/auth/email/verification')->assertStatus(202);
        $this->post($this->verificationLink('ada@example.com'), ['password' => 'correct horse 7'])->assertOk();

        $this->actAs($user);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('email_verified', true);
    }

    public function test_a_proved_address_is_told_so_and_sent_nothing(): void
    {
        $this->owner(verified: true);

        $this->postJson('/api/auth/email/verification')
            ->assertOk()
            ->assertJsonPath('verified', true);

        Mail::assertNotSent(VerifyEmailAddress::class);
    }

    public function test_the_link_is_sent_during_the_request_not_queued(): void
    {
        $this->owner(verified: false);

        $this->postJson('/api/auth/email/verification')->assertStatus(202);

        // A queued email is a copy of a working link sitting in the jobs table.
        Mail::assertNothingQueued();
    }
}
