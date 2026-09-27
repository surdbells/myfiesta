<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\SignUpConfirm;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PendingRegistration;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Accounts\Terms;
use App\Services\Team\TeamService;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FinishesSignUps;
use Tests\TestCase;

/**
 * Nobody signs up or buys without agreeing to the terms, the privacy policy
 * and the refund policy — and what they agreed to is kept.
 *
 * The checkout used to have a box that never reached the server, and signing
 * up asked nothing. Asked later what a buyer had agreed to, there was no
 * answer to give but a guess at what the pages said that day.
 */
class TermsAcceptanceTest extends TestCase
{
    use FinishesSignUps, RefreshDatabase;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        RateLimiter::clear('register:127.0.0.1');
        $this->seed(TaxRateSeeder::class);
        config(['terms.version' => '2026-09-27']);

        $this->event = Event::factory()->published()->create([
            'organization_id' => Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights'])->id,
            'slug' => 'afro-fest',
            'currency' => 'CAD',
            'country' => 'CA',
            'subdivision' => 'ON',
        ]);

        // Free, so an order needs no payment gateway to be placed: what is
        // under test is the box, not the money.
        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 0,
            'status' => 'on_sale',
        ]);
    }

    /** @return array<string, mixed> */
    private function signUp(string $email, array $overrides = []): array
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

    private function buy(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/events/afro-fest/orders', array_merge([
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
            'buyer' => ['name' => 'Ada Okafor', 'email' => 'ada@example.com'],
            'accept_terms' => true,
        ], $overrides));
    }

    // --- signing up ----------------------------------------------------------

    public function test_a_sign_up_without_the_box_ticked_is_refused_in_plain_words(): void
    {
        $body = $this->signUp('ada@example.com');
        unset($body['accept_terms']);

        $this->postJson('/api/auth/register', $body)
            ->assertStatus(422)
            ->assertJsonPath('errors.accept_terms.0', Terms::REFUSAL);

        // Unticked is the same as missing: a box sent as "no" is not a yes.
        $this->postJson('/api/auth/register', $this->signUp('ada@example.com', ['accept_terms' => false]))
            ->assertStatus(422)
            ->assertJsonPath('message', Terms::REFUSAL);

        $this->assertSame(0, PendingRegistration::count());
        Mail::assertNothingSent();
    }

    public function test_the_refusal_is_the_same_for_a_known_address(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/register', $this->signUp('known@example.com', ['accept_terms' => false]));
        $new = $this->postJson('/api/auth/register', $this->signUp('new@example.com', ['accept_terms' => false]));

        // Checked before the address is looked at, so the refusal cannot be
        // used to tell who already has an account.
        $this->assertSame(422, $known->status());
        $this->assertSame($known->getContent(), $new->getContent());
    }

    public function test_the_account_keeps_the_version_and_the_moment_the_box_was_ticked(): void
    {
        $this->freezeSecond();
        $ticked = now();

        $this->postJson('/api/auth/register', $this->signUp('ada@example.com'))->assertStatus(202);

        // Opened the next morning. The agreement was made on the form, by the
        // person who filled it in, not when the link was clicked.
        $this->travel(9)->hours();
        $this->post($this->relative($this->signUpLink('ada@example.com')), ['password' => 'correct horse 7'])->assertOk();

        $user = User::sole();
        $this->assertSame('2026-09-27', $user->terms_version);
        $this->assertTrue($user->terms_accepted_at->equalTo($ticked));
    }

    public function test_the_agreement_is_the_one_the_password_opened(): void
    {
        // A stranger signs up with the address under older terms…
        config(['terms.version' => '2026-01-01']);
        $this->postJson('/api/auth/register', $this->signUp('ada@example.com', [
            'name' => 'Not Ada',
            'password' => 'stranger pass 99',
            'password_confirmation' => 'stranger pass 99',
        ]))->assertStatus(202);

        // …the owner under the current ones, and opens the stranger's link
        // with their own password.
        config(['terms.version' => '2026-09-27']);
        $this->postJson('/api/auth/register', $this->signUp('ada@example.com'))->assertStatus(202);

        $links = [];
        Mail::assertSent(SignUpConfirm::class, function (SignUpConfirm $mail) use (&$links) {
            $links[] = $this->relative($mail->url);

            return true;
        });

        $this->post($links[0], ['password' => 'correct horse 7'])->assertOk();

        // What the owner agreed to, never what the stranger did.
        $this->assertSame('2026-09-27', User::sole()->terms_version);
    }

    public function test_joining_by_invitation_records_the_agreement_there_and_then(): void
    {
        $this->freezeSecond();

        ['token' => $token] = app(TeamService::class)->invite(
            Organization::sole(),
            User::factory()->create(),
            'new.hire@example.com',
            Role::Marketing,
        );

        $body = [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'password' => 'correct horse 42',
            'password_confirmation' => 'correct horse 42',
            'invitation' => $token,
        ];

        $this->postJson('/api/auth/register', $body)
            ->assertStatus(422)
            ->assertJsonPath('errors.accept_terms.0', Terms::REFUSAL);
        $this->assertSame(0, User::where('email', 'new.hire@example.com')->count());

        $this->postJson('/api/auth/register', $body + ['accept_terms' => true])->assertCreated();

        $user = User::where('email', 'new.hire@example.com')->sole();
        $this->assertSame('2026-09-27', $user->terms_version);
        $this->assertTrue($user->terms_accepted_at->equalTo(now()));
    }

    // --- buying --------------------------------------------------------------

    public function test_an_order_without_the_box_ticked_is_refused_and_holds_nothing(): void
    {
        $this->buy(['accept_terms' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', Terms::REFUSAL)
            ->assertJsonPath('errors.accept_terms.0', Terms::REFUSAL);

        $this->buy(['accept_terms' => false])->assertStatus(422);

        // Refused before any stock was held for it.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InventoryHold::count());
    }

    public function test_an_order_keeps_what_its_buyer_agreed_to(): void
    {
        $this->freezeSecond();

        $this->buy()->assertCreated();

        $order = Order::sole();
        $this->assertSame('2026-09-27', $order->terms_version);
        $this->assertTrue($order->terms_accepted_at->equalTo(now()));

        // The words change afterwards. The order still says which it was
        // placed under.
        config(['terms.version' => '2027-03-01']);
        $this->assertSame('2026-09-27', $order->fresh()->terms_version);
    }

    public function test_a_guest_order_leaves_the_account_behind_the_address_alone(): void
    {
        // Anybody can type any address at a guest checkout. Ticking the box
        // there is the buyer agreeing, not the address's owner.
        $owner = User::factory()->create(['email' => 'ada@example.com']);

        $this->buy()->assertCreated();

        $this->assertNull($owner->fresh()->terms_version);
    }

    /**
     * What a signed-in phone or console gets at the checkout today: a guest's
     * checkout.
     *
     * The orders route reads no sign-in, so a real token sent to it is not
     * looked at. The account behind it is neither read (it is asked, though it
     * agreed to these very words) nor written (its agreement stays the one it
     * made), and the order is not placed for it either. Sanctum::actingAs
     * would hide all of that, since it signs the test in on a guard the real
     * route never uses — hence the header, sent the way an app sends it.
     *
     * This is meant to fail on the day a checkout learns who is buying. That
     * change places the order for the account and has the box follow it, as
     * the tests on Terms below describe.
     */
    public function test_a_token_at_the_checkout_is_treated_as_a_guest_today(): void
    {
        $agreed = now()->subMonth()->startOfSecond();
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'terms_version' => '2026-09-27',
            'terms_accepted_at' => $agreed,
        ]);
        $this->withToken($user->createToken('phone', [TokenAbility::Attendee->value])->plainTextToken);

        $this->buy(['accept_terms' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', Terms::REFUSAL);

        $this->freezeSecond();
        $this->buy()->assertCreated();

        $order = Order::sole();
        $this->assertNull($order->user_id);
        $this->assertTrue($order->terms_accepted_at->equalTo(now()));
        $this->assertTrue($user->fresh()->terms_accepted_at->equalTo($agreed));
    }

    // --- an account's agreement, for a checkout that knows its buyer ---------
    //
    // None exists yet (see above), so these call Terms directly rather than
    // dress a test sign-in up as a route that does not read one.

    public function test_an_account_that_agreed_to_the_words_in_force_is_not_asked_again(): void
    {
        $terms = app(Terms::class);
        $user = User::factory()->create(['email' => 'ada@example.com']);

        // Nobody, and an account that never agreed: asked, like anybody.
        $this->assertFalse($terms->acceptedBy(null));
        $this->assertFalse($terms->acceptedBy($user));

        $this->freezeSecond();
        $agreed = now();
        $this->buy()->assertCreated();
        $terms->recordOn($first = Order::sole(), $user, ticked: true);

        $this->assertSame('2026-09-27', $user->fresh()->terms_version);
        $this->assertTrue($terms->acceptedBy($user->fresh()));

        // Two days on, not asked. The order carries the agreement the account
        // holds, made when the box was ticked, not a new one it never made.
        $this->travel(2)->days();
        $this->buy()->assertCreated();
        $latest = Order::whereKeyNot($first->id)->sole();
        $terms->recordOn($latest, $user->fresh(), ticked: false);

        $this->assertSame('2026-09-27', $latest->fresh()->terms_version);
        $this->assertTrue($latest->fresh()->terms_accepted_at->equalTo($agreed));
    }

    public function test_new_words_are_asked_about_again(): void
    {
        $terms = app(Terms::class);
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'terms_version' => '2026-01-01',
            'terms_accepted_at' => now()->subMonth(),
        ]);

        $this->assertFalse($terms->acceptedBy($user));

        $this->buy()->assertCreated();
        $terms->recordOn(Order::sole(), $user, ticked: true);

        $this->assertSame('2026-09-27', $user->fresh()->terms_version);
    }

    public function test_a_sign_up_that_waited_through_new_words_has_not_agreed_to_them(): void
    {
        config(['terms.version' => '2026-01-01']);
        $this->signUpAndConfirm($this->signUp('ada@example.com'));

        config(['terms.version' => '2026-09-27']);

        $this->assertFalse(app(Terms::class)->acceptedBy(User::sole()));
    }

    public function test_an_order_is_not_given_an_agreement_nobody_made(): void
    {
        // A caller other than the checkout, with no box and no account that
        // agreed: nothing is written rather than something made up.
        $this->buy()->assertCreated();
        $order = Order::sole();
        $order->forceFill(['terms_version' => null, 'terms_accepted_at' => null])->save();

        app(Terms::class)->recordOn($order, null, ticked: false);

        $this->assertNull($order->fresh()->terms_version);
        $this->assertNull($order->fresh()->terms_accepted_at);
    }

    public function test_a_door_sale_is_not_asked(): void
    {
        $this->type->update(['price_amount' => 5000]);
        $seller = User::factory()->create();
        $this->event->organization->members()->attach($seller->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);
        Sanctum::actingAs($seller->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        // Cash over a table at 1am: nobody is reading three pages, and the
        // organizer selling agreed to the terms when they signed up.
        $this->postJson("/api/events/{$this->event->id}/door-sales", [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
            'method' => 'cash',
        ])->assertCreated();

        $order = Order::sole();
        $this->assertSame('door', $order->channel);
        $this->assertNull($order->terms_version);
        $this->assertNull($order->terms_accepted_at);
    }
}
