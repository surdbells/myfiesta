<?php

namespace Tests\Feature;

use App\Contracts\Payments\CheckoutOptions;
use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\PaymentEvent;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Contracts\Payments\RefundResult;
use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Events\OrderPaid;
use App\Exceptions\CheckoutException;
use App\Mail\ShareRewardMail;
use App\Mail\TicketsIssued;
use App\Models\Code;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ShareLink;
use App\Models\ShareReward;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Fulfiller;
use App\Services\Events\EventDuplicator;
use App\Services\Events\Insights;
use App\Services\Events\SalesReport;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Subject;
use App\Services\Refunds\RefundService;
use App\Services\Settings\PlatformSettings;
use App\Services\Sharing\ShareOffers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Friend buys, both save.
 *
 * A buyer is given a link once they have paid; a friend who buys through it
 * saves the night's share, through a hidden code nobody can type; once the
 * friend has paid, the buyer is sent a single-use code worth the same, up to
 * a cap, and it is taken back if the friend's order is refunded before it is
 * spent. The organizer pays for both, as with any discount.
 */
class ShareDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $general;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $registry = new PaymentGatewayRegistry;
        $registry->register(new ShareTestGateway);
        $this->app->instance(PaymentGatewayRegistry::class, $registry);

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        // No tax rate seeded: the arithmetic below is about the discount.
        $this->event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
        ]);

        $this->general = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 10000, 'status' => 'on_sale', 'sort_order' => 1]);
    }

    /** The night offers 15% to friends, and a reward for each of up to five. */
    private function offer(int $bps = 1500, int $maxRewards = 5): void
    {
        app(ShareOffers::class)->set($this->event, $bps, $maxRewards);
        $this->event->refresh();
    }

    private function reserve(string $email, ?string $ref = null, ?string $code = null, int $quantity = 1): Order
    {
        return app(CheckoutService::class)->reserve($this->event, [$this->general->id => $quantity], $email, 'Ada Okafor', $code, $ref);
    }

    private function pay(Order $order): Order
    {
        $order->update(['gateway' => 'stripe']);

        return app(Fulfiller::class)->fulfil($order->refresh());
    }

    private function buy(string $email, ?string $ref = null, ?string $code = null): Order
    {
        return $this->pay($this->reserve($email, $ref, $code));
    }

    private function linkOf(string $email): ?ShareLink
    {
        return ShareLink::query()->where('event_id', $this->event->id)->where('owner_email', strtolower($email))->first();
    }

    private function actAs(Role $role): User
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role->value, 'accepted_at' => now()]);
        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        return $user;
    }

    // --- the link -------------------------------------------------------------

    public function test_a_link_is_issued_only_on_a_paid_order_with_the_offer_on(): void
    {
        // No offer: a paid order gets no link.
        $this->buy('first@example.com');
        $this->assertNull($this->linkOf('first@example.com'));

        $this->offer();

        // On, but not yet paid: nothing either.
        $pending = $this->reserve('Ada@Example.com');
        $this->assertSame(0, ShareLink::query()->where('event_id', $this->event->id)->count());

        $this->pay($pending);

        $link = $this->linkOf('ada@example.com');
        $this->assertNotNull($link, 'A paid order with the offer on gives its buyer a link.');
        $this->assertMatchesRegularExpression(ShareLink::SLUG_PATTERN, $link->slug);
        $this->assertSame($pending->id, $link->order_id);

        // A second order is the same person's same link.
        $this->buy('ada@example.com');
        $this->assertSame(1, ShareLink::query()->where('owner_email', 'ada@example.com')->count());
    }

    public function test_the_friends_quote_is_discounted_and_shown_as_a_friends_discount(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $quote = $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 2]],
            'ref' => $link->slug,
        ])->assertOk();

        $quote->assertJsonPath('subtotal.amount', 20000)
            ->assertJsonPath('discount.amount', 3000)
            ->assertJsonPath('friend_discount.discount_bps', 1500)
            ->assertJsonPath('friend_discount.amount', ['amount' => 3000, 'currency' => 'CAD'])
            // Never shown as a code to remove.
            ->assertJsonPath('code_applied', null);

        // A promoter's ref, or a stale one, discounts nothing and is no error.
        $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'ref' => 'fzzzzzzzzzz',
        ])->assertOk()->assertJsonPath('discount.amount', 0)->assertJsonPath('friend_discount', null);
    }

    public function test_an_offer_running_above_a_lowered_platform_cap_is_held_to_it(): void
    {
        $this->offer(2000);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $admin = User::factory()->create(['platform_role' => PlatformRole::Admin, 'email_verified_at' => now()]);

        app(PlatformSettings::class)->update(['share_max_bps' => 1000], $admin);

        $quote = ['items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]], 'ref' => $link->slug];

        $this->postJson('/api/events/afro-fest/quote', $quote)
            ->assertOk()
            ->assertJsonPath('discount.amount', 1000)
            ->assertJsonPath('friend_discount.discount_bps', 1000);
        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.share_offer', ['discount_bps' => 1000]);

        // The friend pays the held figure, and the reward is the same.
        $this->buy('bola@example.com', $link->slug);
        $this->assertSame(1000, ShareReward::query()->firstOrFail()->code->discount_value);
        $this->assertSame(2000, app(ShareOffers::class)->friendCode($this->event)->fresh()->discount_value, 'The organizer’s own figure is left alone.');

        // At 0 the platform has friend discounts off: links take nothing off.
        app(PlatformSettings::class)->update(['share_max_bps' => 0], $admin);

        $this->postJson('/api/events/afro-fest/quote', $quote)
            ->assertOk()
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('friend_discount', null);
        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.share_offer', null);
    }

    public function test_a_typed_code_overrides_the_friends_link(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'SAVE5',
            'discount_type' => 'percentage',
            'discount_value' => 500,
            'is_active' => true,
        ]);

        $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'ref' => $link->slug,
            'code' => 'save5',
        ])->assertOk()
            ->assertJsonPath('discount.amount', 500)
            ->assertJsonPath('code_applied', 'SAVE5')
            ->assertJsonPath('friend_discount', null);

        // And the order that used it earns nobody a reward.
        $order = $this->buy('bola@example.com', $link->slug, 'SAVE5');
        $this->assertNull($order->share_link_id);
        $this->assertSame(0, ShareReward::count());
    }

    public function test_the_hidden_code_cannot_be_typed(): void
    {
        $this->offer();
        $hidden = app(ShareOffers::class)->friendCode($this->event);

        $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'code' => $hidden->code,
        ])->assertStatus(422)->assertJsonPath('message', 'That code is not valid for this event.');
    }

    public function test_using_your_own_link_is_refused(): void
    {
        $this->offer();
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $this->postJson('/api/events/afro-fest/orders', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'buyer' => ['name' => 'Ada', 'email' => 'ADA@example.com'],
            'ref' => $link->slug,
            'accept_terms' => true,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'That’s your own link. Send it to a friend.')
            // Said so a checkout can take the link off and price it again.
            ->assertJsonPath('reason', 'own_share_link');

        $this->assertSame(1, Order::count(), 'Nothing was held for the refused order.');
    }

    // --- the reward -----------------------------------------------------------

    public function test_the_reward_is_issued_once_however_often_the_payment_is_announced(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $friend = $this->reserve('bola@example.com', $link->slug);
        $this->assertSame($link->id, $friend->share_link_id);
        $this->assertSame($link->slug, $friend->ref_slug);

        $this->pay($friend);
        // The processor delivers the same payment again, and the event is
        // heard again besides (a retried queue job).
        $this->pay($friend);
        event(new OrderPaid($friend->fresh()));

        $this->assertSame(1, ShareReward::count());

        $reward = ShareReward::firstOrFail();
        $code = $reward->code;

        $this->assertSame($friend->id, $reward->friend_order_id);
        $this->assertSame(ShareReward::ISSUED, $reward->status);
        $this->assertSame(Code::SHARE_REWARD, $code->purpose);
        $this->assertNull($code->event_id, 'Good for any of the organizer’s nights.');
        $this->assertSame(1500, $code->discount_value);
        $this->assertSame(1, $code->max_redemptions);
        $this->assertTrue($code->ends_at->between(now()->addMonths(12)->subDay(), now()->addMonths(12)->addDay()));
        $this->assertSame(1, $link->fresh()->reward_count);

        Mail::assertQueued(ShareRewardMail::class, 1);
        Mail::assertQueued(ShareRewardMail::class, fn (ShareRewardMail $mail) => $mail->hasTo('ada@example.com'));

        // The friend got a link of their own.
        $this->assertNotNull($this->linkOf('bola@example.com'));
    }

    public function test_the_reward_spends_like_a_code_and_only_once(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $this->buy('bola@example.com', $link->slug);

        $code = ShareReward::firstOrFail()->code;

        $order = $this->buy('ada@example.com', code: strtolower($code->code));
        $this->assertSame(1500, $order->discount_amount);
        $this->assertSame(1, $code->fresh()->redemption_count);

        $this->expectException(CheckoutException::class);
        $this->reserve('chidi@example.com', code: $code->code);
    }

    public function test_a_link_earns_no_more_rewards_than_the_cap(): void
    {
        $this->offer(1500, maxRewards: 2);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        foreach (['bola', 'chidi', 'dayo'] as $friend) {
            $this->buy("{$friend}@example.com", $link->slug);
        }

        $this->assertSame(2, ShareReward::count());
        $this->assertSame(2, $link->fresh()->reward_count);
        Mail::assertQueued(ShareRewardMail::class, 2);

        // The third friend still saved: the cap is on rewards, not on friends.
        $this->assertSame(1500, Order::query()->where('buyer_email', 'dayo@example.com')->value('discount_amount'));
    }

    public function test_a_full_refund_voids_an_unused_reward_and_a_spent_one_stands(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $spentBy = $this->buy('bola@example.com', $link->slug);
        $unusedBy = $this->buy('chidi@example.com', $link->slug);

        $spent = ShareReward::query()->where('friend_order_id', $spentBy->id)->firstOrFail();
        $unused = ShareReward::query()->where('friend_order_id', $unusedBy->id)->firstOrFail();

        // Ada spends the first one on another order.
        $this->buy('ada@example.com', code: $spent->code->code);

        app(RefundService::class)->refund($spentBy->fresh());
        app(RefundService::class)->refund($unusedBy->fresh());

        $this->assertSame(ShareReward::ISSUED, $spent->fresh()->status, 'Already spent: it stands.');
        $this->assertSame(ShareReward::VOIDED, $unused->fresh()->status);
        $this->assertFalse($unused->code->fresh()->is_active);
        $this->assertSame(1, $link->fresh()->reward_count);

        $this->expectException(CheckoutException::class);
        $this->reserve('ada@example.com', code: $unused->code->code);
    }

    public function test_the_organizer_is_charged_the_friends_discount_in_the_ledger(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $friend = $this->buy('bola@example.com', $link->slug);

        $this->assertSame(1500, $friend->discount_amount);
        $this->assertSame(8500, $friend->net_revenue_amount);

        $this->assertSame(-1500, (int) LedgerEntry::query()->where('order_id', $friend->id)->where('type', 'discount')->sum('amount'));
        // What the organizer is owed is the price less the discount: they fund
        // it, not the platform, whose charge is on the 8500 collected.
        $this->assertSame(8500, (int) LedgerEntry::query()->where('order_id', $friend->id)->sum('amount'));
    }

    // --- the organizer --------------------------------------------------------

    public function test_the_organizer_sets_changes_and_ends_the_offer(): void
    {
        $this->actAs(Role::Marketing);

        // Before any offer the console still learns the platform's cap.
        $this->getJson("/api/organizer/events/{$this->event->id}")
            ->assertOk()
            ->assertJsonPath('share_offer.discount_bps', null)
            ->assertJsonPath('share_offer.max_bps', 2000);

        $this->putJson("/api/organizer/events/{$this->event->id}/share-offer", ['discount_bps' => 1500, 'max_rewards' => 3])
            ->assertOk()
            ->assertJsonPath('share_offer.discount_bps', 1500)
            ->assertJsonPath('share_offer.max_rewards', 3)
            ->assertJsonPath('share_offer.max_bps', 2000)
            ->assertJsonPath('share_offer.links', 0);

        $hidden = app(ShareOffers::class)->friendCode($this->event);
        $this->assertSame(1500, $hidden->discount_value);
        $this->assertTrue($hidden->is_active);

        $this->getJson("/api/organizer/events/{$this->event->id}")
            ->assertOk()
            ->assertJsonPath('share_offer.discount_bps', 1500);

        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.share_offer', ['discount_bps' => 1500]);

        // Changing it moves the same code.
        $this->putJson("/api/organizer/events/{$this->event->id}/share-offer", ['discount_bps' => 1000, 'max_rewards' => 3])->assertOk();
        $this->assertSame(1000, $hidden->fresh()->discount_value);
        $this->assertSame(1, Code::query()->where('purpose', Code::SHARE_FRIEND)->count());

        // Ending it turns the code off; a friend's link then discounts nothing.
        $this->putJson("/api/organizer/events/{$this->event->id}/share-offer", ['discount_bps' => null, 'max_rewards' => 3])
            ->assertOk()
            ->assertJsonPath('share_offer.discount_bps', null)
            ->assertJsonPath('share_offer.max_rewards', 3);

        $this->assertFalse($hidden->fresh()->is_active);
        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.share_offer', null);
    }

    public function test_the_offer_is_held_to_the_platforms_cap_and_the_codes_permission(): void
    {
        $this->actAs(Role::Marketing);

        $this->putJson("/api/organizer/events/{$this->event->id}/share-offer", ['discount_bps' => 2500, 'max_rewards' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_bps' => 'A friend discount is at most 20%.']);

        $this->putJson("/api/organizer/events/{$this->event->id}/share-offer", ['discount_bps' => 1500, 'max_rewards' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_rewards']);

        $this->actAs(Role::Door);

        $this->putJson("/api/organizer/events/{$this->event->id}/share-offer", ['discount_bps' => 1500, 'max_rewards' => 5])
            ->assertForbidden();

        $this->assertNull($this->event->fresh()->share_discount_bps);
    }

    public function test_the_codes_lists_keep_to_the_organizers_own_unless_asked(): void
    {
        $this->offer();
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $this->buy('bola@example.com', $link->slug);

        Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'MINE',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'is_active' => true,
        ]);

        $this->actAs(Role::Marketing);

        $this->assertSame(['MINE'], collect($this->getJson('/api/organizer/codes')->assertOk()->json('data'))->pluck('code')->all());
        $this->assertSame(['MINE'], collect($this->getJson("/api/organizer/events/{$this->event->id}/codes")->assertOk()->json('data'))->pluck('code')->all());

        $friend = $this->getJson('/api/organizer/codes?kind=friend')->assertOk()->json('data');
        $this->assertCount(1, $friend);
        $this->assertSame('share_friend', $friend[0]['purpose']);

        $rewards = $this->getJson('/api/organizer/codes?kind=reward')->assertOk()->json('data');
        $this->assertCount(1, $rewards);
        $this->assertSame('share_reward', $rewards[0]['purpose']);

        // The hidden code is changed from the Overview, never by hand.
        $hidden = app(ShareOffers::class)->friendCode($this->event);
        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$hidden->id}", ['discount_value' => 9000])->assertStatus(422);
        $this->deleteJson("/api/organizer/events/{$this->event->id}/codes/{$hidden->id}")->assertStatus(422);
        $this->assertTrue($hidden->fresh()->is_active);
    }

    public function test_the_sales_report_names_friend_sales_and_rewards(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $this->buy('bola@example.com', $link->slug);
        $this->buy('chidi@example.com', $link->slug);

        $reward = ShareReward::query()->firstOrFail()->code;
        $this->buy('ada@example.com', code: $reward->code);

        $rows = collect(app(SalesReport::class)->for($this->event)['codes'])->keyBy('code');

        $this->assertSame(2, $rows['Friend’s discount']['orders']);
        $this->assertSame(3000, $rows['Friend’s discount']['discount']['amount']);
        $this->assertSame('Friends who bought through a buyer’s link', $rows['Friend’s discount']['label']);
        $this->assertNull($rows['Friend’s discount']['ref_slug'], 'One row, not one per link.');

        $this->assertSame(1, $rows['Friend rewards']['orders']);
        $this->assertSame(1500, $rows['Friend rewards']['discount']['amount']);
        $this->assertCount(2, $rows);
    }

    // --- the holder -----------------------------------------------------------

    public function test_a_holder_asks_for_their_link_from_the_phone(): void
    {
        $this->offer();
        $this->buy('ada@example.com');

        $holder = User::factory()->create(['email' => 'Bola@Example.com']);
        Ticket::query()->firstOrFail()->update(['owner_user_id' => $holder->id, 'owner_email' => 'bola@example.com']);

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);

        $first = $this->postJson('/api/events/afro-fest/share-link')->assertOk()->json('data');
        $again = $this->postJson('/api/events/afro-fest/share-link')->assertOk()->json('data');

        $this->assertSame($first['url'], $again['url']);
        $this->assertStringEndsWith('/afro-fest?ref='.$this->linkOf('bola@example.com')->slug, $first['url']);
        $this->assertSame(['discount_bps' => 1500, 'rewards_earned' => 0, 'rewards_left' => 5, 'organizer' => 'Lagos Nights'], array_diff_key($first, ['url' => 1]));
        $this->assertSame($holder->id, $this->linkOf('bola@example.com')->user_id);

        // And the phone's list carries it from then on.
        $this->getJson('/api/me/tickets')->assertOk()->assertJsonPath('data.0.share_link.url', $first['url']);
    }

    public function test_only_a_holder_of_a_live_ticket_gets_a_link_and_only_while_there_is_an_offer(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger, [TokenAbility::Attendee->value]);

        $this->offer();
        $this->postJson('/api/events/afro-fest/share-link')->assertForbidden();

        $this->buy('ada@example.com');
        Ticket::query()->firstOrFail()->update(['owner_user_id' => $stranger->id]);
        app(ShareOffers::class)->set($this->event, null, 5);

        $this->postJson('/api/events/afro-fest/share-link')->assertStatus(422);
        $this->assertSame(0, ShareLink::query()->where('owner_email', strtolower($stranger->email))->count());
    }

    public function test_the_tickets_page_and_email_carry_the_buyers_link(): void
    {
        $this->offer();
        $order = $this->reserve('ada@example.com');
        // Paid, but as if the queued listener had not run yet.
        $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        Ticket::create([
            'event_id' => $this->event->id,
            'order_id' => $order->id,
            'ticket_type_id' => $this->general->id,
            'code' => Ticket::generateCode(),
            'owner_email' => 'ada@example.com',
            'holder_name' => 'Ada Okafor',
            'status' => 'valid',
        ]);

        $page = $this->getJson('/api/tickets/'.$order->access_token)->assertOk();

        $link = $this->linkOf('ada@example.com');
        $this->assertNotNull($link, 'The tickets page makes the buyer’s link if the listener has not.');
        $page->assertJsonPath('tickets.0.share_link.url', rtrim(config('app.public_url'), '/').'/afro-fest?ref='.$link->slug)
            ->assertJsonPath('tickets.0.share_link.discount_bps', 1500);

        $html = (new TicketsIssued($order->fresh()))->render();
        $this->assertStringContainsString('Bring a friend, and you both save', $html);
        $this->assertStringContainsString('afro-fest?ref='.$link->slug, $html);
        $this->assertSame(1, ShareLink::count());
    }

    public function test_the_reward_email_says_what_the_holder_has_and_names_nobody(): void
    {
        $this->offer(1250);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $this->buy('bola@example.com', $link->slug);

        $mail = new ShareRewardMail(ShareReward::query()->firstOrFail());
        $html = $mail->render();

        $this->assertStringContainsString(ShareReward::query()->firstOrFail()->code->code, $html);
        $this->assertStringContainsString('12.5% off', $html);
        $this->assertStringNotContainsString('bola', $html);
        $this->assertSame('A friend used your link: 12.5% off your next tickets from Lagos Nights', $mail->envelope()->subject);
    }

    // --- where a link stops ---------------------------------------------------

    public function test_a_free_ticket_through_a_link_earns_nobody_a_reward(): void
    {
        $this->offer(2000);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $free = TicketType::create(['event_id' => $this->event->id, 'name' => 'Free before 11', 'price_amount' => 0, 'status' => 'on_sale', 'sort_order' => 2]);

        foreach (['alt1', 'alt2'] as $name) {
            $order = app(CheckoutService::class)->reserve($this->event, [$free->id => 1], "{$name}@example.com", 'Alt', null, $link->slug);

            $this->assertSame(0, $order->discount_amount);
            $this->assertNull($order->share_link_id, 'Nothing came off, so there is no saving to give back.');

            app(Fulfiller::class)->fulfilFree($order);
        }

        $this->assertSame(0, ShareReward::count());
        $this->assertSame(0, Code::query()->where('purpose', Code::SHARE_REWARD)->count());

        // Nor is the link's own holder refused a free ticket through it.
        $mine = app(CheckoutService::class)->reserve($this->event, [$free->id => 1], 'ada@example.com', 'Ada', null, $link->slug);
        $this->assertSame('pending', $mine->status);
    }

    public function test_a_reward_part_way_through_a_checkout_is_still_taken_back(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $friend = $this->buy('bola@example.com', $link->slug);
        $reward = ShareReward::query()->firstOrFail();

        // Ada starts paying with it, and her friend is refunded meanwhile.
        $this->reserve('ada@example.com', code: $reward->code->code);
        app(RefundService::class)->refund($friend->fresh());

        $this->assertSame(ShareReward::VOIDED, $reward->fresh()->status);
        $this->assertFalse($reward->code->fresh()->is_active);
        $this->assertSame(0, $link->fresh()->reward_count);

        // Her checkout lapses, and there is nothing left to spend.
        $this->travel(CheckoutService::HOLD_MINUTES + 5)->minutes();

        $this->expectException(CheckoutException::class);
        $this->reserve('someone@example.com', code: $reward->code->code);
    }

    public function test_the_reward_is_what_the_friend_was_priced_at(): void
    {
        $this->offer(1000);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $friend = $this->reserve('bola@example.com', $link->slug);
        $this->assertSame(1000, $friend->discount_amount);

        // The organizer raises the offer while Bola is on the payment page.
        $this->offer(2000);
        $this->pay($friend);

        $this->assertSame(1000, ShareReward::query()->firstOrFail()->code->discount_value);
    }

    public function test_a_link_stops_once_its_holder_is_refunded(): void
    {
        $this->offer(1500);
        $mine = $this->buy('ada@example.com');
        $link = $this->linkOf('ada@example.com');

        app(RefundService::class)->refund($mine->fresh());

        $friend = $this->buy('bola@example.com', $link->slug);

        $this->assertSame(0, $friend->discount_amount);
        $this->assertNull($friend->share_link_id);
        $this->assertSame(0, ShareReward::count());
    }

    public function test_erasing_a_holder_keeps_the_rewards_a_refund_can_take_back(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $friend = $this->buy('bola@example.com', $link->slug);
        $reward = ShareReward::query()->firstOrFail();

        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        $this->assertNull($this->linkOf('ada@example.com'), 'Her address is off the link.');
        $this->assertNull($link->fresh()->user_id);
        $this->assertSame(1, ShareReward::count(), 'The reward is still there to take back.');
        $this->getJson('/api/events/afro-fest/friend-discount?ref='.$link->slug)->assertNotFound();

        app(RefundService::class)->refund($friend->fresh());

        $this->assertSame(ShareReward::VOIDED, $reward->fresh()->status);
        $this->assertFalse($reward->code->fresh()->is_active);
    }

    public function test_a_night_whose_sales_are_over_asks_nobody_to_share(): void
    {
        $this->offer(1500);
        $order = $this->buy('ada@example.com');
        $holder = User::factory()->create(['email' => 'ada.phone@example.com']);
        Ticket::query()->firstOrFail()->update(['owner_user_id' => $holder->id]);

        $this->travelTo($this->event->starts_at->copy()->addDay());

        $this->getJson('/api/events/afro-fest')->assertOk()->assertJsonPath('data.share_offer', null);
        $this->getJson('/api/tickets/'.$order->access_token)->assertOk()->assertJsonPath('tickets.0.share_link', null);

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);
        $this->postJson('/api/events/afro-fest/share-link')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sales for this night are over, so there is no discount left to share.');

        // The console still shows the offer as it was set.
        $this->actAs(Role::Marketing);
        $this->getJson("/api/organizer/events/{$this->event->id}")->assertOk()->assertJsonPath('share_offer.discount_bps', 1500);
    }

    public function test_the_event_page_can_ask_whether_a_ref_takes_money_off(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $this->getJson('/api/events/afro-fest/friend-discount?ref='.strtoupper($link->slug))
            ->assertOk()
            ->assertExactJson(['data' => ['discount_bps' => 1500]]);

        // A promoter's slug of the same shape, a link to nothing, no ref at
        // all, another night: one answer for each.
        foreach (['fridaynight', 'fzzzzzzzzzz', ''] as $ref) {
            $this->getJson('/api/events/afro-fest/friend-discount?ref='.$ref)->assertNotFound();
        }

        $this->getJson('/api/events/somewhere-else/friend-discount?ref='.$link->slug)->assertNotFound();
    }

    public function test_a_promoter_slug_cannot_look_like_a_friends_link(): void
    {
        $this->actAs(Role::Marketing);

        $code = fn (string $slug) => [
            'code' => strtoupper(str_replace('-', '', $slug)),
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'ref_slug' => $slug,
        ];

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", $code('fridaynight'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ref_slug' => 'That looks like a buyer’s friend link (an f and ten letters or numbers). Add a hyphen, or pick another.']);

        $this->postJson("/api/organizer/events/{$this->event->id}/codes", $code('friday-night'))->assertCreated();

        // An older code that already has one is not refused over it when the
        // rest of it is edited.
        $older = Code::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'code' => 'FIESTA',
            'ref_slug' => 'fiestagirls',
            'is_active' => true,
        ]);

        $this->patchJson("/api/organizer/events/{$this->event->id}/codes/{$older->id}", ['label' => 'Fiesta girls', 'ref_slug' => 'fiestagirls'])->assertOk();
    }

    public function test_the_insights_count_friends_links_apart_from_promoters(): void
    {
        $this->offer(1500);
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);
        $this->buy('bola@example.com', $link->slug);

        $sources = collect(app(Insights::class)->for($this->event)['sources'])->keyBy('source');

        $this->assertSame(['direct', 'friend'], $sources->keys()->all());
        $this->assertSame(1, $sources['friend']['orders']);
        $this->assertSame(1, $sources['direct']['orders']);
    }

    // --- copies and the contract ----------------------------------------------

    public function test_a_copy_carries_the_offer_with_a_code_of_its_own(): void
    {
        $this->offer(1500, maxRewards: 3);

        $copy = app(EventDuplicator::class)->duplicate($this->event, now()->addMonths(2));

        $this->assertSame(1500, (int) $copy->share_discount_bps);
        $this->assertSame(3, (int) $copy->share_max_rewards);

        $original = app(ShareOffers::class)->friendCode($this->event);
        $copied = app(ShareOffers::class)->friendCode($copy);
        $this->assertNotNull($copied);
        $this->assertNotSame($original->id, $copied->id);
        $this->assertSame($copy->id, $copied->event_id);
    }

    public function test_the_shapes_are_the_ones_the_contract_declares(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));
        $required = fn (string $schema) => $spec['components']['schemas'][$schema]['required'];

        $this->offer();
        $link = $this->linkOf($this->buy('ada@example.com')->buyer_email);

        $offer = $this->getJson('/api/events/afro-fest')->assertOk()->json('data.share_offer');
        $friend = $this->postJson('/api/events/afro-fest/quote', [
            'items' => [['ticket_type_id' => $this->general->id, 'quantity' => 1]],
            'ref' => $link->slug,
        ])->assertOk()->json('friend_discount');
        $held = $this->getJson('/api/tickets/'.Order::query()->firstOrFail()->access_token)->assertOk()->json('tickets.0.share_link');

        $checked = $this->getJson('/api/events/afro-fest/friend-discount?ref='.$link->slug)->assertOk()->json('data');

        $this->assertSame($required('ShareOffer'), array_keys($offer));
        $this->assertSame($required('ShareOffer'), array_keys($checked));
        $this->assertSame($required('FriendDiscount'), array_keys($friend));
        $this->assertSame($required('ShareLink'), array_keys($held));

        $this->actAs(Role::Marketing);
        $organizer = $this->getJson("/api/organizer/events/{$this->event->id}")->assertOk()->json('share_offer');
        $this->assertSame($required('OrganizerShareOffer'), array_keys($organizer));
    }
}

class ShareTestGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'stripe';
    }

    public function supports(string $currency): bool
    {
        return true;
    }

    public function createCheckout(Order $order, CheckoutOptions $options): CheckoutSession
    {
        throw new \LogicException('Not used.');
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        return null;
    }

    public function refund(Order $order, int $amountMinorUnits, ?string $reason = null, ?string $idempotencyKey = null): RefundResult
    {
        return new RefundResult(true, 're_share', $amountMinorUnits, $order->currency);
    }
}
