<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\PayoutDestinationChanged;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every owner hears when the payouts are pointed somewhere new.
 *
 * Changing the destination is owner-only and audit-logged, but a log is read
 * after the money has gone. An owner's account is what somebody takes over to
 * redirect a payout, so the moment it happens is when the real owners need to
 * hear about it — all of them, and the one whose account did it.
 *
 * The rest is about the email itself being safe to send: no account number,
 * and nothing typed by the person it may be warning about turning into a link.
 */
class PayoutDestinationAlertTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        // The organization's zone, for an owner who has not chosen one.
        Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function member(Role $role, array $attributes = [], ?Organization $organization = null): User
    {
        $user = User::factory()->create($attributes);

        ($organization ?? $this->org)->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function saveBank(array $overrides = []): TestResponse
    {
        return $this->putJson('/api/organizer/payout-details', $overrides + [
            'rail' => 'bank_transfer',
            'account_name' => 'Lagos Nights Inc',
            'bank_name' => 'Royal Bank',
            'account_number' => '1234567',
            'transit_number' => '00012',
            'institution_number' => '003',
        ]);
    }

    private function saveInterac(string $email): TestResponse
    {
        return $this->putJson('/api/organizer/payout-details', ['rail' => 'interac', 'interac_email' => $email]);
    }

    private function verifiedInterac(): OrganizationPayoutDetail
    {
        return OrganizationPayoutDetail::create([
            'organization_id' => $this->org->id,
            'rail' => 'interac',
            'currency' => 'CAD',
            'interac_email' => 'money@lagosnights.test',
            'verified_at' => now(),
            'verification_method' => 'interac_test_transfer',
        ]);
    }

    /** The one email that went to this address. */
    private function sentTo(User $user): PayoutDestinationChanged
    {
        $found = null;

        Mail::assertQueued(PayoutDestinationChanged::class, function (PayoutDestinationChanged $mail) use ($user, &$found) {
            if (! $mail->hasTo($user->email)) {
                return false;
            }

            $found = $mail;

            return true;
        });

        return $found;
    }

    /**
     * The words are all there to read, and none of them can be clicked — or
     * fetched, since an image loads from the sender's server when opened.
     */
    private function readsAsText(string $html): bool
    {
        return str_contains($html, 'evil.example') && ! preg_match('/<(a|img)\b[^>]*evil\.example/', $html);
    }

    // --- who hears ------------------------------------------------------------

    public function test_every_owner_hears_and_nobody_else_does(): void
    {
        $this->verifiedInterac();

        $ada = $this->member(Role::Owner, ['name' => 'Ada Okafor', 'email' => 'ada@lagosnights.test']);
        $bola = $this->member(Role::Owner, ['name' => 'Bola Adeyemi', 'email' => 'bola@lagosnights.test']);

        $others = [
            $this->member(Role::Manager),
            $this->member(Role::Finance),
            $this->member(Role::Marketing),
            $this->member(Role::Door),
            // An owner, but of somebody else's organization.
            $this->member(Role::Owner, [], Organization::create(['name' => 'Elsewhere', 'slug' => 'elsewhere'])),
        ];

        $this->signIn($ada);
        $this->saveBank()->assertOk();

        // Both owners — the one who did it too, since if it was not them this
        // is how they find out somebody is using their account.
        Mail::assertQueued(PayoutDestinationChanged::class, 2);
        Mail::assertQueued(PayoutDestinationChanged::class, fn ($mail) => $mail->hasTo($ada->email) && $mail->byThem);
        Mail::assertQueued(PayoutDestinationChanged::class, fn ($mail) => $mail->hasTo($bola->email) && ! $mail->byThem);

        // Each to one address. One email with every owner on it would hand
        // each of them the others' addresses and lose the "you" in it.
        Mail::assertQueued(PayoutDestinationChanged::class, fn ($mail) => count($mail->to) === 1);

        foreach ($others as $user) {
            Mail::assertNotQueued(PayoutDestinationChanged::class, fn ($mail) => $mail->hasTo($user->email));
        }
    }

    public function test_it_is_queued_and_encrypted_on_the_queue(): void
    {
        $owner = $this->member(Role::Owner);
        $this->signIn($owner);

        $this->saveBank()->assertOk();

        $mail = $this->sentTo($owner);

        $this->assertInstanceOf(ShouldQueue::class, $mail);

        // The bank's name is encrypted wherever else it is kept; a job row
        // holding it in the clear would undo that.
        $this->assertInstanceOf(ShouldBeEncrypted::class, $mail);
    }

    public function test_saving_the_same_details_again_tells_nobody(): void
    {
        $owner = $this->member(Role::Owner);
        $this->signIn($owner);

        // The first time is a change: from nowhere to somewhere.
        $this->saveBank()->assertOk();
        Mail::assertQueued(PayoutDestinationChanged::class, 1);
        $this->assertSame('Payout details added for Lagos Nights', $this->sentTo($owner)->envelope()->subject);

        // Opening the form and saving it as it was is not.
        $this->saveBank()->assertOk();
        Mail::assertQueued(PayoutDestinationChanged::class, 1);

        // Nor for Interac, where there is no last four to tell the two apart.
        $this->saveInterac('money@lagosnights.test')->assertOk();
        Mail::assertQueued(PayoutDestinationChanged::class, 2);

        $this->saveInterac('money@lagosnights.test')->assertOk();
        Mail::assertQueued(PayoutDestinationChanged::class, 2);
    }

    public function test_a_refused_change_tells_nobody(): void
    {
        $this->verifiedInterac();
        $this->member(Role::Owner);

        foreach ([Role::Manager, Role::Finance] as $role) {
            $this->signIn($this->member($role));
            $this->saveBank()->assertForbidden();
        }

        Mail::assertNothingQueued();
    }

    // --- what it says -----------------------------------------------------------

    public function test_it_says_where_it_goes_now_and_before_without_the_account_number(): void
    {
        $this->verifiedInterac();

        $ada = $this->member(Role::Owner, ['name' => 'Ada Okafor', 'email' => 'ada@lagosnights.test']);
        $bola = $this->member(Role::Owner, ['name' => 'Bola Adeyemi', 'email' => 'bola@lagosnights.test']);

        $this->signIn($ada);
        $this->saveBank()->assertOk();

        $mail = $this->sentTo($bola);
        $body = $mail->render();

        $this->assertSame('Payout details changed for Lagos Nights', $mail->envelope()->subject);

        // Who, to whom, and where it goes: the bank and the last four, as the
        // Payouts page shows it.
        $this->assertStringContainsString('Hello Bola Adeyemi.', $body);
        $this->assertStringContainsString('Ada Okafor (ada@lagosnights.test) changed where payouts go for', $body);
        $this->assertStringContainsString('Bank transfer to Royal Bank, account ending 4567', $body);

        // Where it went before, with the Interac address cut down to what
        // lets an owner recognise it.
        $this->assertStringContainsString('Interac e-Transfer to m•••@lagosnights.test', $body);
        $this->assertStringNotContainsString('money@lagosnights.test', $body);

        // What happens next, as it actually happens: the change cleared the
        // verification, and staff are warned before paying to it. Warned, not
        // stopped — a payout can still be recorded with a reason — so it must
        // not tell an owner the money is held, which would tell them to wait.
        $this->assertNull(OrganizationPayoutDetail::sole()->verified_at);
        $this->assertStringContainsString('our team is warned before sending', $body);
        $this->assertStringContainsString('not a lock on the money', $body);
        $this->assertStringNotContainsString('waits', $body);
        $this->assertStringContainsString(config('app.console_url').'/payouts', $body);

        // Never the account number, nor the numbers that route it — not in
        // the email, and not in the copy of it that waits on the queue.
        foreach ([$body, serialize($mail)] as $haystack) {
            $this->assertStringNotContainsString('1234567', $haystack);
            $this->assertStringNotContainsString('00012', $haystack);
            $this->assertStringNotContainsString('money@lagosnights.test', $haystack);
        }
    }

    public function test_it_tells_the_owner_who_made_the_change_what_to_do_if_it_was_not_them(): void
    {
        $ada = $this->member(Role::Owner, ['name' => 'Ada Okafor', 'email' => 'ada@lagosnights.test']);
        $bola = $this->member(Role::Owner, ['name' => 'Bola Adeyemi', 'email' => 'bola@lagosnights.test']);

        $this->signIn($ada);
        $this->saveInterac('money@lagosnights.test')->assertOk();

        $own = $this->sentTo($ada)->render();

        $this->assertStringContainsString('You added payout details for', $own);
        $this->assertStringContainsString('If this was not you', $own);
        $this->assertStringContainsString(config('app.console_url').'/forgot-password', $own);

        $theirs = $this->sentTo($bola)->render();

        $this->assertStringContainsString('Check with Ada Okafor', $theirs);
        $this->assertStringContainsString('reply', $theirs);
    }

    public function test_a_change_that_reads_the_same_masked_still_says_it_is_a_change(): void
    {
        $owner = $this->member(Role::Owner);
        $this->signIn($owner);

        $this->saveBank()->assertOk();

        // Same bank, same last four, a different branch: a different account.
        $this->saveBank(['transit_number' => '00099'])->assertOk();

        Mail::assertQueued(PayoutDestinationChanged::class, 2);

        Mail::assertQueued(PayoutDestinationChanged::class, fn ($mail) => $mail->previous !== null
            && str_contains($mail->render(), 'That reads the same as before'));
    }

    public function test_the_time_is_written_in_the_readers_zone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-26 19:04:00', 'UTC'));

        // One has chosen a zone; the other gets the one the organization's
        // nights are in. Both carry the abbreviation, so neither has to guess.
        $ada = $this->member(Role::Owner, ['timezone' => 'Africa/Lagos']);
        $bola = $this->member(Role::Owner);

        $this->signIn($ada);
        $this->saveInterac('money@lagosnights.test')->assertOk();

        $this->assertStringContainsString('26 September 2026 at 8:04 pm WAT', $this->sentTo($ada)->render());
        $this->assertStringContainsString('26 September 2026 at 3:04 pm EDT', $this->sentTo($bola)->render());
    }

    /**
     * How a link can be started: the bracket itself, the bracket behind a
     * backslash of the typist's own — which Markdown reads as a plain
     * backslash and then a link, once the bracket has been escaped — and an
     * image, which loads from their server the moment the email is opened.
     *
     * @return array<string, array{string}>
     */
    public static function linkOpeners(): array
    {
        return [
            'a bracket' => ['['],
            'a backslash before the bracket' => ['\\['],
            'an image' => ['!['],
            'an image behind a backslash' => ['!\\['],
        ];
    }

    #[DataProvider('linkOpeners')]
    public function test_what_was_typed_never_becomes_a_link(string $open): void
    {
        // Bank names are any text, and so are names. After a takeover, the
        // person typing them is the one this email warns about, and a link of
        // theirs beside "reply to this email" is the phishing email they would
        // most like us to send.
        $this->org->update(['name' => $open.'Lagos Nights](https://evil.example/org)']);

        $ada = $this->member(Role::Owner, [
            'name' => $open.'Your payouts are on hold](https://evil.example/name)',
            // An address may put nearly anything in quotes before the @, and
            // the changer's is written out in full to the other owners.
            'email' => '"'.$open.'Undo this](https://evil.example/by)"@lagosnights.test',
        ]);
        $bola = $this->member(Role::Owner);

        $this->signIn($ada);

        $this->saveBank(['bank_name' => $open.'Confirm your bank](https://evil.example/bank)'])->assertOk();

        // And the Interac address — the bank above becomes the "before" line
        // of this one.
        $this->saveInterac('"'.$open.'cancel it here](https://evil.example/address)"@evil.example')->assertOk();

        Mail::assertQueued(PayoutDestinationChanged::class, 4);

        foreach ([$ada, $bola] as $owner) {
            Mail::assertQueued(PayoutDestinationChanged::class, fn ($mail) => $mail->hasTo($owner->email)
                && $this->readsAsText($mail->render()));
        }

        Mail::assertNotQueued(PayoutDestinationChanged::class, fn ($mail) => ! $this->readsAsText($mail->render()));

        // Still there to read, words and all.
        Mail::assertQueued(PayoutDestinationChanged::class, fn ($mail) => $mail->hasTo($bola->email)
            && str_contains($mail->render(), 'Confirm your bank](https://evil.example/bank)'));
    }

    public function test_laravels_own_switch_neither_weakens_it_nor_is_turned_off_by_it(): void
    {
        // Laravel's switch escapes the bracket but not the backslash, and while
        // it is on its escaping is used in place of ours. Somebody may turn it
        // on for every mail one day: these must stay as safe as they are, and
        // must leave it on for the rest.
        $owner = User::factory()->create(['name' => 'Bola Adeyemi']);
        $by = User::factory()->create(['name' => '\\[Undo this](https://evil.example/by)']);
        $organization = $this->org;

        $mail = fn () => new PayoutDestinationChanged(
            $owner, $by, $organization, 'Bank transfer to Royal Bank, account ending 4567', null, now(), 'America/Toronto',
        );

        $isOn = fn () => Closure::bind(fn () => static::$withSecuredEncoding, null, Markdown::class)();

        Markdown::withSecuredEncoding();

        try {
            $this->assertTrue($this->readsAsText($mail()->render()));
            $this->assertTrue($isOn());
        } finally {
            Markdown::withoutSecuredEncoding();
        }

        // And off, as it is by default, it stays off.
        $this->assertTrue($this->readsAsText($mail()->render()));
        $this->assertFalse($isOn());
    }

    // --- where a reply goes -------------------------------------------------------

    public function test_a_reply_reaches_the_support_inbox(): void
    {
        config(['mail.support.address' => 'help@myfiesta.test']);

        $owner = $this->member(Role::Owner);
        $this->signIn($owner);
        $this->saveInterac('money@lagosnights.test')->assertOk();

        $this->assertTrue($this->sentTo($owner)->hasReplyTo('help@myfiesta.test'));
    }

    public function test_without_a_support_inbox_a_reply_goes_to_the_from_address(): void
    {
        config(['mail.support.address' => null, 'mail.from.address' => 'hello@myfiesta.test']);

        $owner = $this->member(Role::Owner);
        $this->signIn($owner);
        $this->saveInterac('money@lagosnights.test')->assertOk();

        // Not the ideal inbox, but one that exists: the email tells people to
        // reply, and a reply has to land somewhere.
        $this->assertTrue($this->sentTo($owner)->hasReplyTo('hello@myfiesta.test'));
    }
}
