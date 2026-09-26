<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Mail\EmailChangeAddressInUse;
use App\Mail\EmailChangeConfirm;
use App\Mail\EmailChanged;
use App\Mail\EmailChangeRequested;
use App\Mail\EventReminderMail;
use App\Models\EmailChange;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Invites\RsvpService;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Moving an account to a new email address.
 *
 * The address is how an account is recovered, so changing it is how an
 * account is stolen: move it somewhere you read, ask for a reset link, and
 * the owner is locked out of their own tickets. These tests are mostly about
 * what it takes to do that — the password, and the new inbox — and about the
 * endpoint not becoming a way to learn who else holds an account here.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->user = User::factory()->create([
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'password' => 'the right one 12',
        ]);
    }

    private function ask(string $email, string $password = 'the right one 12', ?string $token = null): TestResponse
    {
        if ($token !== null) {
            return $this->withToken($token)->postJson('/api/auth/email', ['email' => $email, 'current_password' => $password]);
        }

        Sanctum::actingAs($this->user, [TokenAbility::Attendee->value]);

        return $this->postJson('/api/auth/email', ['email' => $email, 'current_password' => $password]);
    }

    /** The plain token, as only the email to the new address carries it. */
    private function linkSentTo(string $email): string
    {
        $token = null;

        Mail::assertSent(EmailChangeConfirm::class, function (EmailChangeConfirm $mail) use ($email, &$token) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $token = $mail->token;

            return true;
        });

        return $token;
    }

    /** Opened the way a link is: from a mail app, signed in to nothing. */
    private function open(string $token): TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/email/confirm', ['token' => $token]);
    }

    /** The words are all there to read, and none of them can be clicked. */
    private function readsAsText(string $html): bool
    {
        return str_contains($html, 'evil.example') && ! preg_match('/<a\b[^>]*evil\.example/', $html);
    }

    private function night(string $kind = 'ticketed'): Event
    {
        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights-'.Str::random(6)]);

        return Event::create([
            'organization_id' => $org->id,
            'slug' => 'night-'.Str::random(6),
            'title' => 'Afro Fest',
            'kind' => $kind,
            'currency' => 'CAD',
            'starts_at' => now()->addDays(10),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function ticketFor(Event $event, string $email): Ticket
    {
        $type = TicketType::firstOrCreate(
            ['event_id' => $event->id, 'name' => 'General'],
            ['price_amount' => 5000, 'status' => 'on_sale'],
        );

        return Ticket::create([
            'event_id' => $event->id,
            'ticket_type_id' => $type->id,
            'code' => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'owner_user_id' => $this->user->id,
            'owner_email' => $email,
            'holder_name' => 'Ada Okafor',
            'status' => 'valid',
            'admits' => 1,
            'admitted_count' => 0,
        ]);
    }

    // --- asking --------------------------------------------------------------

    public function test_asking_needs_the_current_password(): void
    {
        $this->ask('new@example.com', 'a guess')
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'That is not your current password.');

        // A borrowed, unlocked laptop is signed in. It does not know the
        // password, and without it nothing is sent anywhere.
        Mail::assertNothingSent();
        $this->assertSame(0, EmailChange::count());
    }

    public function test_the_address_already_in_use_is_refused_plainly(): void
    {
        $this->ask('ADA@example.com')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'That is the address you already use.');

        Mail::assertNothingSent();
    }

    public function test_the_link_goes_to_the_new_address_and_a_warning_to_the_old_one(): void
    {
        $this->ask('New@Example.com')
            ->assertStatus(202)
            ->assertJsonPath('message', 'Check new@example.com for a link to confirm it. Nothing changes until it is opened, and it works for an hour.');

        Mail::assertSent(EmailChangeConfirm::class, fn ($mail) => $mail->hasTo('new@example.com'));
        Mail::assertSent(EmailChangeRequested::class, fn ($mail) => $mail->hasTo('ada@example.com') && $mail->newEmail === 'new@example.com');
        Mail::assertSentCount(2);

        // Nothing has changed yet. The old address still signs in, and still
        // gets the password resets.
        $this->assertSame('ada@example.com', $this->user->fresh()->email);

        // Stored as a hash: the database holds nothing that would complete it.
        $token = $this->linkSentTo('new@example.com');
        $change = EmailChange::sole();
        $this->assertSame('new@example.com', $change->email);
        $this->assertNotSame($token, $change->token_hash);
        $this->assertSame(EmailChange::hashToken($token), $change->token_hash);
    }

    public function test_the_link_opens_the_console_not_the_api(): void
    {
        $this->ask('new@example.com');

        $token = $this->linkSentTo('new@example.com');
        $url = rtrim(config('app.console_url'), '/').'/confirm-email?token='.$token;

        // The API serves no pages. A link to it would be a link to a 404.
        Mail::assertSent(EmailChangeConfirm::class, fn ($mail) => str_contains($mail->render(), $url));

        // The warning names the new address, so the owner can recognise it —
        // and tells them what to do if it is not theirs.
        Mail::assertSent(EmailChangeRequested::class, function ($mail) {
            $body = $mail->render();

            return str_contains($body, 'new@example.com') && str_contains($body, config('app.console_url').'/forgot-password');
        });
    }

    public function test_the_link_to_a_new_address_carries_nothing_the_asker_typed(): void
    {
        $this->user->update(['name' => '[Your tickets are on hold](https://evil.example/x)']);

        $this->ask('new@example.com');

        // Any account can send this to any address at all. Whatever it carried
        // of the asker's would be theirs to put in front of a stranger, in an
        // email from us.
        Mail::assertSent(EmailChangeConfirm::class, function ($mail) {
            $body = $mail->render();

            return ! str_contains($body, 'evil.example') && ! str_contains($body, 'ada@example.com');
        });
    }

    public function test_what_the_asker_typed_never_becomes_a_link_in_the_warnings(): void
    {
        // Both accepted as they are: a name is any text, and an address may
        // put nearly anything in quotes before the @.
        $this->user->update(['name' => '[Your tickets are on hold](https://evil.example/name)']);
        $address = '"[cancel it here](https://evil.example/address)"@example.com';

        $this->ask($address)->assertStatus(202);

        // After a takeover the person asking is the one these warn about, and
        // a link of theirs beside "set a new password" is the phishing email
        // they would most like to send.
        Mail::assertSent(EmailChangeRequested::class, fn ($mail) => $this->readsAsText($mail->render()));

        $this->open($this->linkSentTo($address))->assertOk();

        Mail::assertSent(EmailChanged::class, fn ($mail) => $this->readsAsText($mail->render()));
    }

    public function test_an_address_with_an_account_gets_the_same_answer_and_cannot_be_moved_to(): void
    {
        $free = $this->ask('free@example.com');

        User::factory()->create(['email' => 'taken@example.com']);
        $someoneElse = User::factory()->create(['email' => 'chidi@example.com', 'password' => 'the right one 12']);
        $this->user = $someoneElse;

        $taken = $this->ask('taken@example.com');

        // The same status and the same sentence, apart from the address that
        // was typed. Anything else tells the person asking who else holds an
        // account here.
        $this->assertSame($free->status(), $taken->status());
        $this->assertSame(
            str_replace('free@example.com', 'X', $free->getContent()),
            str_replace('taken@example.com', 'X', $taken->getContent()),
        );

        // No link went to it. A change is left waiting all the same, because
        // other screens can see that one is — but its token went nowhere, so
        // nothing can open it.
        Mail::assertNotSent(EmailChangeConfirm::class, fn ($mail) => $mail->hasTo('taken@example.com'));
        $this->assertSame('taken@example.com', EmailChange::where('user_id', $someoneElse->id)->sole()->email);

        // Its owner is told somebody tried, with nothing that completes anything.
        Mail::assertSent(EmailChangeAddressInUse::class, fn ($mail) => $mail->hasTo('taken@example.com')
            && ! str_contains($mail->render(), 'confirm-email'));

        // And the account's own address is warned exactly as it would have been.
        Mail::assertSent(EmailChangeRequested::class, fn ($mail) => $mail->hasTo('chidi@example.com'));

        $this->assertSame('chidi@example.com', $someoneElse->fresh()->email);
    }

    public function test_an_address_held_by_a_closed_account_cannot_be_moved_to_either(): void
    {
        User::factory()->create(['email' => 'gone@example.com'])->delete();

        $this->ask('gone@example.com')->assertStatus(202);

        Mail::assertNotSent(EmailChangeConfirm::class);
        Mail::assertSent(EmailChangeAddressInUse::class, fn ($mail) => $mail->hasTo('gone@example.com'));
    }

    public function test_an_address_kept_as_it_was_typed_still_counts_as_taken(): void
    {
        // Imported accounts kept their capitals. The unique index would not
        // stop a second account at the same address in lower case, so this has
        // to.
        User::factory()->create(['email' => 'Taken@Example.com']);

        $this->ask('taken@example.com')->assertStatus(202);

        Mail::assertNotSent(EmailChangeConfirm::class);
        Mail::assertSent(EmailChangeAddressInUse::class, fn ($mail) => $mail->hasTo('taken@example.com'));
    }

    public function test_asking_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->ask("guess{$i}@example.com", "guess {$i}")->assertStatus(422);
        }

        // Counted whether or not the password was right, so an open session is
        // not an unthrottled way to guess it. The right one is refused too.
        $this->ask('new@example.com')
            ->assertStatus(429)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_asking_again_replaces_the_earlier_link(): void
    {
        $this->ask('first@example.com');
        $first = $this->linkSentTo('first@example.com');

        $this->ask('second@example.com');
        $second = $this->linkSentTo('second@example.com');

        $this->assertSame(1, EmailChange::count());

        // Two links that both move the account is one more than anybody meant.
        $this->open($first)->assertStatus(422);
        $this->open($second)->assertOk();

        $this->assertSame('second@example.com', $this->user->fresh()->email);
    }

    public function test_asking_again_for_an_address_in_use_still_ends_the_earlier_link(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->ask('first@example.com');
        $first = $this->linkSentTo('first@example.com');

        $this->ask('taken@example.com');

        // If the earlier link survived only when the new address was taken,
        // trying it afterwards would give the answer away.
        $this->open($first)->assertStatus(422);
        $this->assertSame('ada@example.com', $this->user->fresh()->email);
    }

    // --- opening the link ------------------------------------------------------

    public function test_opening_the_link_moves_the_account(): void
    {
        $this->ask('new@example.com');

        $this->open($this->linkSentTo('new@example.com'))
            ->assertOk()
            ->assertJsonPath('email', 'new@example.com')
            ->assertJsonPath('message', 'Your account now uses new@example.com. Other devices have been signed out.');

        $user = $this->user->fresh();
        $this->assertSame('new@example.com', $user->email);

        // Opening the link is exactly what proves the address.
        $this->assertNotNull($user->email_verified_at);

        // Nothing is left waiting.
        $this->assertSame(0, EmailChange::count());

        // The old address is told, and it is the last it hears.
        Mail::assertSent(EmailChanged::class, fn ($mail) => $mail->hasTo('ada@example.com')
            && str_contains($mail->render(), 'new@example.com'));

        // Signing in follows the address.
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => 'new@example.com', 'password' => 'the right one 12'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'the right one 12'])->assertStatus(422);
    }

    public function test_opening_the_link_signs_out_every_other_device_but_the_one_that_asked(): void
    {
        $this->user->createToken('old phone', [TokenAbility::Attendee->value]);
        $this->user->createToken('old tablet', [TokenAbility::Attendee->value]);
        $laptop = $this->user->createToken('this laptop', [TokenAbility::Attendee->value]);

        $this->ask('new@example.com', token: $laptop->plainTextToken)->assertStatus(202);

        $this->open($this->linkSentTo('new@example.com'))->assertOk();

        // As a new password does: the session that proved the password stays,
        // and a phone somebody else is holding does not.
        $this->assertSame(['this laptop'], $this->user->tokens()->pluck('name')->all());
    }

    public function test_tickets_the_account_holds_follow_it_to_the_new_address(): void
    {
        $event = $this->night();
        $bought = $this->ticketFor($event, 'Ada@Example.com');
        $forWork = $this->ticketFor($event, 'ada@work.example');

        $this->ask('new@example.com');
        $this->open($this->linkSentTo('new@example.com'))->assertOk();

        // Reminders and organizers' messages go to the address on the ticket.
        // Left on the old one, they keep reaching an inbox somebody may have
        // moved away from because they lost it.
        $this->assertSame('new@example.com', $bought->fresh()->owner_email);

        // Bought for a different address, it stays where the buyer sent it.
        $this->assertSame('ada@work.example', $forWork->fresh()->owner_email);

        $reminder = $event->reminders()->create(['offset_minutes' => 60, 'status' => 'scheduled']);
        app(ReminderDispatcher::class)->send($reminder);

        Mail::assertQueued(EventReminderMail::class, fn ($mail) => $mail->hasTo('new@example.com'));
        Mail::assertNotQueued(EventReminderMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_a_guest_list_invitation_stays_with_the_address_the_host_has(): void
    {
        $wedding = $this->night('invitation');
        $guest = Guest::create([
            'event_id' => $wedding->id,
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'max_party_size' => 2,
            'invite_token' => Guest::freshToken(),
            'invited_at' => now(),
        ]);

        $rsvp = app(RsvpService::class);
        $rsvp->respond($guest, 'attending', 2);

        $this->ask('new@example.com');
        $this->open($this->linkSentTo('new@example.com'))->assertOk();

        // The host's list still says ada@example.com, and a guest's reply is
        // matched to their tickets by it. Had they moved, this would find none
        // and issue two more.
        $rsvp->respond($guest->fresh(), 'attending', 2);

        $this->assertSame(2, Ticket::where('event_id', $wedding->id)->where('status', 'valid')->count());
    }

    public function test_what_the_old_address_said_no_to_the_new_one_says_no_to(): void
    {
        EmailPreference::forEmail('ada@example.com')->forceFill(['marketing_opted_out_at' => now()])->save();

        $this->ask('new@example.com');
        $this->open($this->linkSentTo('new@example.com'))->assertOk();

        // Opt-outs are kept by address. Moving is not asking for announcements
        // again, and nobody should start getting them because they did.
        $this->assertSame([], EmailPreference::marketable(['new@example.com']));

        // Only the no is carried. Reminders were never turned off, and still
        // are not.
        $this->assertSame(['new@example.com'], EmailPreference::remindable(['new@example.com']));
    }

    public function test_a_reset_link_sent_to_the_old_address_stops_working(): void
    {
        $reset = Password::createToken($this->user);

        $this->ask('new@example.com');
        $this->open($this->linkSentTo('new@example.com'))->assertOk();

        // Keyed on the address, not the account. Left alone, it would set the
        // password of whoever signs up with ada@example.com next.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ada@example.com']);

        $this->postJson('/api/auth/reset-password', [
            'token' => $reset,
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
            'password_confirmation' => 'a whole new one 9',
        ])->assertStatus(422);
    }

    public function test_a_link_works_once(): void
    {
        $this->ask('new@example.com');
        $token = $this->linkSentTo('new@example.com');

        $this->open($token)->assertOk();

        $this->open($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That link has expired or has already been used. Ask for a new one from your account.');
    }

    public function test_an_expired_link_is_refused(): void
    {
        $this->ask('new@example.com');
        $token = $this->linkSentTo('new@example.com');

        $this->travel(EmailChange::EXPIRES_MINUTES + 1)->minutes();

        $this->open($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That link has expired or has already been used. Ask for a new one from your account.');

        $this->assertSame('ada@example.com', $this->user->fresh()->email);
    }

    public function test_a_made_up_link_is_refused_the_same_way(): void
    {
        $this->ask('new@example.com');

        // One answer for every link that cannot work, so guessing learns
        // nothing about which ones nearly did.
        $this->open('not-a-real-token')
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That link has expired or has already been used. Ask for a new one from your account.');

        $this->open('')->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertSame('ada@example.com', $this->user->fresh()->email);
    }

    public function test_an_address_taken_after_the_link_was_sent_is_not_moved_to(): void
    {
        $this->ask('new@example.com');
        $token = $this->linkSentTo('new@example.com');

        User::factory()->create(['email' => 'new@example.com']);

        $this->open($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'That address now belongs to another account, so yours cannot move to it.');

        $this->assertSame('ada@example.com', $this->user->fresh()->email);
        Mail::assertNotSent(EmailChanged::class);
    }

    // --- when it was not them ---------------------------------------------------

    public function test_a_new_password_cancels_a_change_that_is_waiting(): void
    {
        $session = $this->user->createToken('this laptop', [TokenAbility::Attendee->value]);

        $this->ask('new@example.com', token: $session->plainTextToken);
        $token = $this->linkSentTo('new@example.com');

        // What the warning to the old address tells somebody to do. It has to
        // actually stop the link at the other end.
        $this->withToken($session->plainTextToken)
            ->postJson('/api/auth/password', [
                'current_password' => 'the right one 12',
                'password' => 'a better one 34',
                'password_confirmation' => 'a better one 34',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password changed. Other devices have been signed out. The new email address you asked for was cancelled too — ask again if you still want it.');

        $this->open($token)->assertStatus(422);
        $this->assertSame('ada@example.com', $this->user->fresh()->email);
    }

    public function test_a_new_password_says_the_same_whichever_address_was_asked_for(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $chidi = User::factory()->create(['email' => 'chidi@example.com', 'password' => 'the right one 12']);

        $answers = [];

        foreach ([[$this->user, 'free@example.com'], [$chidi, 'taken@example.com']] as [$user, $address]) {
            $this->app['auth']->forgetGuards();
            $session = $user->createToken('this laptop', [TokenAbility::Attendee->value])->plainTextToken;

            $this->ask($address, token: $session)->assertStatus(202);

            $answers[] = $this->withToken($session)
                ->postJson('/api/auth/password', [
                    'current_password' => 'the right one 12',
                    'password' => 'a better one 34',
                    'password_confirmation' => 'a better one 34',
                ])
                ->assertOk()
                ->getContent();
        }

        // Asking answers the same either way, so what comes after it must too.
        // Otherwise asking for somebody's address and then changing your own
        // password tells you whether they have an account here.
        $this->assertSame($answers[0], $answers[1]);
    }

    public function test_a_password_reset_cancels_a_change_that_is_waiting(): void
    {
        $this->ask('new@example.com');
        $token = $this->linkSentTo('new@example.com');

        // The owner who does not know what the password was changed to resets
        // it from the old address, which still works until the link is opened.
        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($this->user),
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
            'password_confirmation' => 'a whole new one 9',
        ])->assertOk();

        $this->open($token)->assertStatus(422);
        $this->assertSame('ada@example.com', $this->user->fresh()->email);
    }
}
