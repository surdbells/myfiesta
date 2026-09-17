<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Signing up as somebody who is going out, not putting something on.
 *
 * Guest checkout is the primary path here, so most people arrive holding a
 * ticket and no account. Asking them to name an events page before they can
 * keep that ticket on their phone is asking about a business they do not have.
 */
class AttendeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $body = [])
    {
        return $this->postJson('/api/auth/register', array_merge([
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple 7',
            'password_confirmation' => 'correct horse battery staple 7',
            'attendee' => true,
            'device' => 'mobile',
        ], $body));
    }

    public function test_an_attendee_signs_up_without_naming_an_events_page(): void
    {
        $this->register()->assertCreated()->assertJsonPath('organizations', []);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertSame(0, User::where('email', 'ada@example.com')->first()->organizations()->count());
    }

    public function test_the_token_cannot_reach_the_organizer_screens(): void
    {
        $token = $this->register()->json('token');

        $stored = PersonalAccessToken::findToken($token);

        // The ability comes from being staff somewhere, not from which door
        // somebody signed up through.
        $this->assertTrue($stored->can(TokenAbility::Attendee->value));
        $this->assertFalse($stored->can(TokenAbility::Organizer->value));

        $this->withToken($token)->getJson('/api/organizer/events')->assertForbidden();
        $this->withToken($token)->getJson('/api/me/tickets')->assertOk();
    }

    public function test_an_organizer_still_has_to_say_what_to_call_their_events_page(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple 7',
            'password_confirmation' => 'correct horse battery staple 7',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.organization.0', 'What should we call your events page?');
    }

    public function test_an_organizer_signup_is_unchanged(): void
    {
        $body = $this->postJson('/api/auth/register', [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple 7',
            'password_confirmation' => 'correct horse battery staple 7',
            'organization' => 'Lagos Nights',
        ])->assertCreated()->json();

        $this->assertSame('Lagos Nights', $body['organizations'][0]['name']);
        $this->assertTrue(PersonalAccessToken::findToken($body['token'])->can(TokenAbility::Organizer->value));
    }

    public function test_an_address_already_in_use_is_not_reported_as_such(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        // Saying "that email is taken" turns sign-up into a way to test who
        // holds an account here.
        $this->register()->assertStatus(202)->assertJsonPath('pending', true);
    }

    public function test_asking_for_a_reset_link_says_the_same_thing_either_way(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com'])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
    }
}
