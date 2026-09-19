<?php

namespace Tests\Feature;

use App\Contracts\Sms\SmsResult;
use App\Contracts\Sms\SmsSender;
use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PhonePreference;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\Fulfiller;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Sms\PhoneNumber;
use App\Services\Sms\Texts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Texts: the ticket, and the reminder before the doors.
 *
 * Nothing here sells anything — a marketing text needs consent this platform
 * does not collect — so what is protected is that only those two go out, that
 * a number that replied STOP never hears from us again including for its own
 * ticket, and that an ambiguous number is refused rather than guessed at,
 * because a guess sends a stranger somebody's ticket link.
 */
class SmsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsSender $sms;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);

        Config::set('sms.countries', ['234']);
        Config::set('sms.inbound_secret', 'inbound-secret');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'detty-december',
            'title' => 'Detty December',
            'currency' => 'NGN',
            'starts_at' => now()->addHours(5),
            'timezone' => 'Africa/Lagos',
            'city' => 'Lagos',
            'country' => 'NG',
            'status' => 'published',
        ]);

        TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 500000, 'status' => 'on_sale']);
    }

    private function order(?string $phone): Order
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada',
            'buyer_phone' => $phone,
            'currency' => 'NGN',
            'subtotal_amount' => 500000,
            'total_amount' => 500000,
            'net_revenue_amount' => 500000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->event->ticketTypes()->value('id'),
            'order_id' => $order->id,
            'owner_email' => 'ada@example.com',
            'code' => strtoupper(Str::random(12)),
            'status' => 'valid',
        ]);

        return $order->fresh()->load('event');
    }

    // --- what is sent ------------------------------------------------------------

    public function test_a_ticket_text_carries_the_link_and_fits_one_message(): void
    {
        $order = $this->order('+2348012345678');

        $this->assertTrue(app(Texts::class)->ticketsReady($order));

        [$to, $message] = $this->sms->last();
        $this->assertSame('+2348012345678', $to);
        $this->assertStringContainsString('Detty December', $message);
        $this->assertStringContainsString($order->access_token, $message);
        // Longer than this is billed, and sometimes delivered, as two.
        $this->assertLessThanOrEqual(Texts::ONE_PART, mb_strlen($message));
    }

    public function test_a_ticket_bought_with_a_phone_number_gets_both(): void
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => 'ada@example.com',
            'buyer_name' => 'Ada',
            'buyer_phone' => '+2348012345678',
            'currency' => 'NGN',
            'subtotal_amount' => 500000,
            'total_amount' => 500000,
            'net_revenue_amount' => 500000,
            'status' => 'pending',
        ]);

        $order->lines()->create([
            'ticket_type_id' => $this->event->ticketTypes()->value('id'),
            'name' => 'General',
            'unit_price_amount' => 500000,
            'quantity' => 1,
            'line_total_amount' => 500000,
        ]);

        app(Fulfiller::class)->fulfil($order->refresh());

        Mail::assertQueued(TicketsIssued::class);
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_the_last_reminder_is_also_a_text_and_the_earlier_ones_are_not(): void
    {
        $this->order('+2348012345678');

        // A week out: an email, and no text.
        $early = $this->event->reminders()->create(['offset_minutes' => 7 * 24 * 60, 'status' => 'scheduled']);
        $this->event->update(['starts_at' => now()->addMinutes(7 * 24 * 60)->subMinute()]);
        app(ReminderDispatcher::class)->send($early->fresh()->load('event'));

        $this->assertCount(0, $this->sms->sent);

        // Three hours out: both.
        $late = $this->event->reminders()->create(['offset_minutes' => 3 * 60, 'status' => 'scheduled']);
        $this->event->update(['starts_at' => now()->addMinutes(3 * 60)->subMinute()]);
        app(ReminderDispatcher::class)->send($late->fresh()->load('event'));

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('tonight', $this->sms->last()[1]);
    }

    // --- who is not texted ---------------------------------------------------------

    public function test_a_number_that_said_stop_hears_nothing_at_all(): void
    {
        $order = $this->order('+2348012345678');

        $this->post('/webhooks/sms/inbound-secret', ['from' => '+2348012345678', 'message' => 'STOP'])->assertOk();

        // Including the ticket itself: somebody who said stop has said stop,
        // and the email still has their tickets in it.
        $this->assertFalse(app(Texts::class)->ticketsReady($order));
        $this->assertCount(0, $this->sms->sent);

        // And back again on request.
        $this->post('/webhooks/sms/inbound-secret', ['From' => '+2348012345678', 'Body' => 'start'])->assertOk();
        $this->assertTrue(app(Texts::class)->ticketsReady($order));
    }

    public function test_a_country_we_do_not_text_is_not_texted(): void
    {
        // Canada: the email arrives and is read, and a text costs money to say
        // the same thing twice.
        $this->assertFalse(app(Texts::class)->ticketsReady($this->order('+15551234567')));
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_an_order_with_no_number_is_not_a_failure(): void
    {
        $this->assertFalse(app(Texts::class)->ticketsReady($this->order(null)));
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_a_provider_refusing_does_not_break_anything(): void
    {
        $this->sms->refuse();

        $this->assertFalse(app(Texts::class)->ticketsReady($this->order('+2348012345678')));
    }

    // --- the number itself ------------------------------------------------------------

    public function test_only_an_unambiguous_number_is_used(): void
    {
        $this->assertSame('+2348012345678', PhoneNumber::e164('+234 801 234 5678'));
        $this->assertSame('+2348012345678', PhoneNumber::e164('00234-801-234-5678'));

        // A local number could belong to any country. Guessing sends somebody
        // else a stranger's ticket link.
        $this->assertNull(PhoneNumber::e164('08012345678'));
        $this->assertNull(PhoneNumber::e164('+1'));
        $this->assertNull(PhoneNumber::e164('not a number'));
        $this->assertNull(PhoneNumber::e164(null));
    }

    public function test_the_stop_list_survives_being_written_twice(): void
    {
        $this->assertTrue(PhonePreference::optOut('+2348012345678', 'replied stop'));
        $this->assertFalse(PhonePreference::optOut('+234 801 234 5678'));

        $this->assertSame(1, PhonePreference::count());
    }

    public function test_the_inbound_endpoint_is_not_open_to_anybody(): void
    {
        $this->post('/webhooks/sms/wrong-secret', ['from' => '+2348012345678', 'message' => 'STOP'])->assertNotFound();

        $this->assertSame(0, PhonePreference::count());
    }

    public function test_anything_that_is_not_a_stop_word_is_ignored(): void
    {
        // Nobody is reading these. Acting on them would be worse than the
        // silence.
        $this->post('/webhooks/sms/inbound-secret', ['from' => '+2348012345678', 'message' => 'what time do doors open?'])
            ->assertOk();

        $this->assertSame(0, PhonePreference::count());
    }
}

/**
 * A provider that never reaches a network.
 *
 * The real ones are two HTTP calls each; faking the interface rather than the
 * HTTP keeps the test about what we decide to send, which is the part with
 * rules in it.
 */
class FakeSmsSender implements SmsSender
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    private bool $accepts = true;

    public function refuse(): void
    {
        $this->accepts = false;
    }

    public function send(string $to, string $message): SmsResult
    {
        if (! $this->accepts) {
            return SmsResult::refused('Provider is down.');
        }

        $this->sent[] = [$to, $message];

        return SmsResult::accepted('fake');
    }

    public function name(): string
    {
        return 'fake';
    }

    /** @return array{0: string, 1: string} */
    public function last(): array
    {
        return $this->sent[count($this->sent) - 1];
    }
}
