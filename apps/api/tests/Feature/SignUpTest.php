<?php

namespace Tests\Feature;

use App\Mail\SignUpAddressInUse;
use App\Mail\SignUpConfirm;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FinishesSignUps;
use Tests\TestCase;

/**
 * Signing up tells the form nothing about who already has an account, and
 * makes nothing until the address is proved by the person who asked.
 *
 * The form used to answer a new address with a session and a known one with
 * "check your email" — which was the enumeration it meant to prevent, in a
 * different word. And an account made on the spot could be made for anybody's
 * address, with every ticket that address later bought as a guest landing in it.
 */
class SignUpTest extends TestCase
{
    use FinishesSignUps, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        RateLimiter::clear('register:127.0.0.1');
    }

    /** @return array<string, mixed> */
    private function form(string $email, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Okafor',
            'email' => $email,
            'password' => 'correct horse 7',
            'password_confirmation' => 'correct horse 7',
            'organization' => 'Lagos Nights',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_a_known_address_and_a_new_one_get_the_same_answer(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/register', $this->form('known@example.com'));
        $new = $this->postJson('/api/auth/register', $this->form('new@example.com'));

        // Status, body and headers: anything that differs is the answer to
        // "does this address have an account here?".
        $this->assertSame(202, $known->status());
        $this->assertSame($known->status(), $new->status());
        $this->assertSame($known->getContent(), $new->getContent());
        $this->assertSame(
            array_keys(array_diff_key($known->headers->all(), ['date' => 0])),
            array_keys(array_diff_key($new->headers->all(), ['date' => 0])),
        );
        $new->assertJsonMissingPath('token');
    }

    public function test_each_gets_one_email_sent_during_the_request(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/auth/register', $this->form('known@example.com'))->assertStatus(202);
        $this->postJson('/api/auth/register', $this->form('new@example.com'))->assertStatus(202);

        Mail::assertSent(SignUpAddressInUse::class, 1);
        Mail::assertSent(SignUpAddressInUse::class, fn ($mail) => $mail->hasTo('known@example.com'));
        Mail::assertSent(SignUpConfirm::class, 1);
        Mail::assertSent(SignUpConfirm::class, fn ($mail) => $mail->hasTo('new@example.com'));

        // Neither waits for the queue. One queued and one not would answer
        // the form at different speeds, which is the same leak by the clock.
        Mail::assertNothingQueued();
    }

    public function test_the_email_carries_nothing_the_stranger_typed(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com', [
            'name' => 'Click here for a prize',
            'organization' => 'visit evil.example',
        ]))->assertStatus(202);

        Mail::assertSent(SignUpConfirm::class, function (SignUpConfirm $mail) {
            $html = $mail->render();

            // Anybody can sign up with any address, so whatever the form said
            // would be words a stranger put in front of somebody else in an
            // email from us.
            return ! str_contains($html, 'prize') && ! str_contains($html, 'evil.example');
        });
    }

    public function test_the_page_shows_nothing_the_stranger_typed(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com', [
            'name' => 'Your confirmation password is Blue-Sky-4471',
            'organization' => 'visit evil.example',
        ]))->assertStatus(202);
        $link = $this->relative($this->signUpLink('new@example.com'));

        // The page is where the password is typed. A name shown just above
        // that box is a stranger telling the inbox's owner what to type, and
        // the account it finishes would open with the stranger's password.
        $this->get($link)
            ->assertOk()
            ->assertSee('new@example.com')
            ->assertDontSee('Blue-Sky-4471')
            ->assertDontSee('evil.example');

        // Nor when a wrong password brings the page back.
        $this->post($link, ['password' => 'a guess 12345'])
            ->assertStatus(422)
            ->assertDontSee('Blue-Sky-4471')
            ->assertDontSee('evil.example');
    }

    public function test_nothing_is_made_until_the_link_is_confirmed(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com'))->assertStatus(202);

        $this->assertSame(0, User::count());

        // A mail scanner following the link makes nothing either.
        $this->get($this->relative($this->signUpLink('new@example.com')))
            ->assertOk()
            ->assertSee('Make my account');

        $this->assertSame(0, User::count());
    }

    public function test_confirming_needs_the_password_that_was_chosen(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com'))->assertStatus(202);
        $link = $this->relative($this->signUpLink('new@example.com'));

        // Somebody who reads the inbox but did not fill in the form — the
        // owner of an address a stranger signed up with — cannot finish it.
        $this->post($link, ['password' => 'a guess 12345'])->assertStatus(422);
        $this->post($link)->assertStatus(422);
        $this->assertSame(0, User::count());

        $this->post($link, ['password' => 'correct horse 7'])->assertOk()->assertSee('Your account is ready');

        $user = User::sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('correct horse 7', $user->password));
    }

    public function test_a_link_opens_nothing_for_another_address(): void
    {
        $this->postJson('/api/auth/register', $this->form('ada@example.com'))->assertStatus(202);
        $this->postJson('/api/auth/register', $this->form('bola@example.com', [
            'password' => 'bolas own 1234',
            'password_confirmation' => 'bolas own 1234',
        ]))->assertStatus(202);

        // A link tries the sign-ups waiting for its own address and no other:
        // it proves one inbox, not every inbox somebody knows a password for.
        $this->post($this->relative($this->signUpLink('ada@example.com')), ['password' => 'bolas own 1234'])
            ->assertStatus(422);

        $this->assertSame(0, User::count());
        $this->assertSame(2, PendingRegistration::count());
    }

    public function test_a_link_stops_listening_after_five_wrong_passwords(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com'))->assertStatus(202);
        $link = $this->relative($this->signUpLink('new@example.com'));

        for ($i = 0; $i < 5; $i++) {
            $this->post($link, ['password' => "wrong guess {$i}"])->assertStatus(422);
        }

        $this->post($link, ['password' => 'correct horse 7'])->assertStatus(429);
        $this->assertSame(0, User::count());
    }

    public function test_a_link_works_once(): void
    {
        $this->signUpAndConfirm($this->form('new@example.com'));

        $this->post($this->relative($this->signUpLink('new@example.com')), ['password' => 'correct horse 7'])
            ->assertStatus(410);

        $this->assertSame(1, User::count());
    }

    public function test_a_link_dies_after_a_day(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com'))->assertStatus(202);
        $link = $this->relative($this->signUpLink('new@example.com'));

        $this->travel(PendingRegistration::EXPIRES_HOURS + 1)->hours();

        $this->get($link)->assertStatus(410);
        $this->post($link, ['password' => 'correct horse 7'])->assertStatus(410);
        $this->assertSame(0, User::count());
    }

    public function test_a_tampered_link_is_dead(): void
    {
        $this->postJson('/api/auth/register', $this->form('new@example.com'))->assertStatus(202);
        $link = $this->relative($this->signUpLink('new@example.com'));

        $this->post(preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $link), ['password' => 'correct horse 7'])
            ->assertStatus(410);

        $this->assertSame(0, User::count());
    }

    public function test_a_stranger_cannot_cancel_the_owners_sign_up(): void
    {
        // Somebody else signs up with the address first, with their own name
        // and password. The owner's own sign-up still gets its own link…
        $this->postJson('/api/auth/register', $this->form('ada@example.com', [
            'name' => 'Not Ada',
            'password' => 'stranger pass 99',
            'password_confirmation' => 'stranger pass 99',
        ]))->assertStatus(202);
        $this->postJson('/api/auth/register', $this->form('ada@example.com'))->assertStatus(202);

        $links = [];
        Mail::assertSent(SignUpConfirm::class, function (SignUpConfirm $mail) use (&$links) {
            $links[] = $this->relative($mail->url);

            return true;
        });

        [$strangers, $owners] = $links;

        // …and whichever of the two the owner opens, the owner's password
        // finishes the owner's sign-up. Never the stranger's: that needs the
        // stranger's password, which the owner does not know.
        $this->post($strangers, ['password' => 'correct horse 7'])->assertOk();

        $user = User::sole();
        $this->assertTrue(Hash::check('correct horse 7', $user->password));
        $this->assertSame('Ada Okafor', $user->name);

        // Every other sign-up for the address is spent with it.
        $this->post($owners, ['password' => 'correct horse 7'])->assertStatus(410);
        $this->post($strangers, ['password' => 'stranger pass 99'])->assertStatus(410);
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_a_stranger_cannot_use_up_the_owners_emails(): void
    {
        // A stranger, from three places, spends the hour's emails to the
        // address on sign-ups of their own.
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/auth/register', $this->form('ada@example.com', [
                    'name' => 'Not Ada',
                    'organization' => 'Not Lagos Nights',
                    'password' => 'stranger pass 99',
                    'password_confirmation' => 'stranger pass 99',
                ]))
                ->assertStatus(202);
        }

        // The owner's own sign-up gets no email of its own…
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/auth/register', $this->form('ada@example.com'))
            ->assertStatus(202);
        Mail::assertSent(SignUpConfirm::class, 3);

        // …and is still finished, from a link the stranger's sign-ups put in
        // the owner's inbox, with the owner's password and the owner's details.
        $this->post($this->relative($this->signUpLink('ada@example.com')), ['password' => 'correct horse 7'])
            ->assertOk()
            ->assertSee('Your account is ready');

        $user = User::sole();
        $this->assertTrue(Hash::check('correct horse 7', $user->password));
        $this->assertSame('Ada Okafor', $user->name);
        $this->assertSame(['Lagos Nights'], $user->organizations()->pluck('name')->all());
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_an_address_that_bought_as_a_guest_is_claimed_with_its_tickets(): void
    {
        // A guest checkout leaves an account with no password: nobody can
        // sign in to it, and signing up is how its owner does.
        $guest = User::factory()->create(['email' => 'ada@example.com', 'password' => null, 'email_verified_at' => null]);

        $this->signUpAndConfirm($this->form('ada@example.com'));

        $this->assertSame($guest->id, User::sole()->id);
        $this->assertNotNull(User::sole()->email_verified_at);
        Mail::assertNotSent(SignUpAddressInUse::class);
    }

    public function test_an_address_signed_up_in_the_meantime_is_not_taken_over(): void
    {
        $this->postJson('/api/auth/register', $this->form('ada@example.com'))->assertStatus(202);
        $link = $this->relative($this->signUpLink('ada@example.com'));

        // The address gets a working account some other way before the link
        // is opened — by invitation, say.
        $owner = User::factory()->create(['email' => 'ada@example.com', 'password' => 'their own 12345']);

        $this->post($link, ['password' => 'correct horse 7'])->assertOk()->assertSee('already has an account');

        $this->assertTrue(Hash::check('their own 12345', $owner->fresh()->password));
    }

    public function test_one_inbox_cannot_be_flooded_from_many_places(): void
    {
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::clear('register:127.0.0.1');
            $this->postJson('/api/auth/register', $this->form('ada@example.com'))->assertStatus(202);
        }

        // Three an hour per address, whoever asks. The answer does not change,
        // so the limit says nothing either.
        Mail::assertSent(SignUpConfirm::class, 3);

        // The limit is on the emails. The sign-ups past it are kept, and the
        // links already sent open them.
        $this->assertSame(5, PendingRegistration::count());
    }
}
