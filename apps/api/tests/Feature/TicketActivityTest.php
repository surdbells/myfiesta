<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Mail\YourTicket;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketTransfer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * What happened to an order's tickets between the sale and the door.
 *
 * "I never got my tickets" is answered by this list: issued, emailed and taken
 * by the mail provider under its own id, the link opened, the QR on the phone.
 * Written as each happens, into a table the database will not let anybody
 * edit — and never with a ticket code in it.
 */
class TicketActivityTest extends TestCase
{
    use RefreshDatabase, SellsTicketsForDisputes;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    private const ADDRESS = '198.51.100.23';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();
    }

    /** An order bought and paid, its tickets issued and emailed. */
    private function paidOrder(): Order
    {
        [$event, $type] = $this->night();
        $order = $this->buy($event, $type);

        $this->stripePaid($order, 'pi_'.Str::random(24))->assertOk();

        return $order->refresh();
    }

    /** @return Collection<int, TicketActivity> */
    private function history(Order $order, string $kind)
    {
        return TicketActivity::query()->where('order_id', $order->id)->where('kind', $kind)->orderBy('occurred_at')->get();
    }

    private function open(string $uri, string $agent = self::BROWSER, string $from = self::ADDRESS)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeaders(['User-Agent' => $agent])
            ->get($uri);
    }

    public function test_issuing_the_tickets_is_written_down_by_id(): void
    {
        $order = $this->paidOrder();

        $issued = $this->history($order, TicketActivity::ISSUED)->sole();

        $this->assertSame(2, $issued->details['count']);
        $this->assertEqualsCanonicalizing(
            Ticket::where('order_id', $order->id)->pluck('id')->all(),
            $issued->details['tickets'],
        );
        $this->assertSame($order->event_id, $issued->event_id);
        $this->assertNull($issued->ip_address);
    }

    public function test_the_ticket_email_is_written_down_with_where_it_went_and_its_message_id(): void
    {
        $order = $this->paidOrder();

        $mailed = $this->history($order, TicketActivity::EMAILED)->sole();

        $sent = app('mailer')->getSymfonyTransport()->messages()->last();

        $this->assertSame('TicketsIssued', $mailed->mailable);
        $this->assertSame('ada@example.com', $mailed->recipient_email);
        $this->assertSame($sent->getMessageId(), $mailed->message_id);
        $this->assertSame('Your tickets for Afro Fest', $mailed->details['subject']);
        $this->assertCount(2, $mailed->details['tickets']);
        $this->assertNull($mailed->ip_address);
    }

    public function test_the_mail_providers_own_id_is_the_one_kept(): void
    {
        $order = $this->paidOrder();
        $ticket = Ticket::where('order_id', $order->id)->firstOrFail();

        config(['services.zeptomail.api_key' => Str::random(40)]);
        $this->processor['api.zeptomail.com/v1.1/email'] = fn () => Http::response([
            'data' => [['code' => 'EM_104', 'additional_info' => [], 'message' => 'Email request received']],
            'message' => 'OK',
            'request_id' => '2d6f.3e9b1e1a0b62ad86.m1.9c1f7e40',
            'object' => 'email',
        ]);

        Mail::mailer('zeptomail')->to('ada@example.com')->send(new YourTicket($ticket->fresh(['event.venue', 'event.organization', 'ticketType'])));

        $mailed = TicketActivity::query()->where('ticket_id', $ticket->id)->where('kind', TicketActivity::EMAILED)->sole();

        $this->assertSame('YourTicket', $mailed->mailable);
        $this->assertSame('2d6f.3e9b1e1a0b62ad86.m1.9c1f7e40', $mailed->message_id);
        $this->assertSame($order->id, $mailed->order_id);
    }

    public function test_mail_about_no_order_and_no_ticket_is_not_written_down(): void
    {
        Mail::raw('Hello.', fn ($message) => $message->to('someone@example.com')->subject('Hello'));

        $this->assertSame(0, TicketActivity::count());
    }

    public function test_opening_the_ticket_link_is_written_down_once_per_visit_with_the_address_and_browser(): void
    {
        $order = $this->paidOrder();

        $this->open("/api/tickets/{$order->access_token}")->assertOk();
        $this->open("/api/tickets/{$order->access_token}")->assertOk();

        $opened = $this->history($order, TicketActivity::TICKET_PAGE);
        $this->assertCount(1, $opened);
        $this->assertSame(self::ADDRESS, $opened->first()->ip_address);
        $this->assertSame(self::BROWSER, $opened->first()->user_agent);

        // Another address is another opening; so is the same one later on.
        $this->open("/api/tickets/{$order->access_token}", 'Mozilla/5.0 (Macintosh) Safari/605.1.15', '203.0.113.9')->assertOk();
        $this->travel(config('disputes.activity.repeat_minutes') + 1)->minutes();
        $this->open("/api/tickets/{$order->access_token}")->assertOk();

        $this->assertCount(3, $this->history($order, TicketActivity::TICKET_PAGE));
    }

    public function test_a_new_browser_name_from_the_same_address_does_not_pad_the_history(): void
    {
        $order = $this->paidOrder();

        foreach (range(1, 30) as $n) {
            $this->open("/api/tickets/{$order->access_token}", "bot/{$n}")->assertOk();
        }

        $this->assertCount(1, $this->history($order, TicketActivity::TICKET_PAGE));
    }

    public function test_a_link_that_opens_nothing_writes_nothing(): void
    {
        $this->open('/api/tickets/'.Str::random(44))->assertNotFound();
        $this->open('/api/tickets/'.Str::random(44).'/calendar.ics')->assertNotFound();

        $this->assertSame(0, TicketActivity::whereIn('kind', TicketActivity::ACCESS)->count());
    }

    public function test_the_page_that_waits_for_the_payment_writes_nothing_however_it_is_asked(): void
    {
        // Shows no ticket, needs only the reference, and is asked by the
        // site's own server too: nothing it is asked is an opening.
        [$event, $type] = $this->night();
        $unpaid = $this->buy($event, $type);
        $paid = $this->paidOrder();
        $before = TicketActivity::count();

        foreach ([$unpaid, $paid] as $order) {
            foreach (range(1, 20) as $n) {
                $this->open("/api/orders/{$order->reference}", "bot/{$n}")->assertOk();
            }
        }

        $this->assertSame($before, TicketActivity::count());
    }

    public function test_the_signed_order_link_is_written_down(): void
    {
        $order = $this->paidOrder();

        $this->open(URL::signedRoute('orders.show', $order))->assertOk();

        $this->assertSame(self::ADDRESS, $this->history($order, TicketActivity::ORDER_LINK)->sole()->ip_address);
    }

    public function test_the_calendar_file_comes_through_the_orders_own_link_and_is_written_down(): void
    {
        $order = $this->paidOrder();

        $link = $this->getJson("/api/tickets/{$order->access_token}")->assertOk()->json('event.calendar.ics_url');
        $this->assertSame(url("/api/tickets/{$order->access_token}/calendar.ics"), $link);

        $file = $this->open("/api/tickets/{$order->access_token}/calendar.ics")->assertOk();

        $this->assertStringStartsWith('text/calendar', (string) $file->headers->get('Content-Type'));
        $this->assertStringContainsString('BEGIN:VEVENT', (string) $file->getContent());
        $this->assertStringContainsString('private', (string) $file->headers->get('Cache-Control'));

        $this->assertSame(self::BROWSER, $this->history($order, TicketActivity::CALENDAR)->sole()->user_agent);
    }

    public function test_the_app_drawing_each_qr_is_written_down_per_ticket(): void
    {
        $order = $this->paidOrder();
        $holder = User::where('email', 'ada@example.com')->firstOrFail();

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);

        $this->withServerVariables(['REMOTE_ADDR' => self::ADDRESS])
            ->withHeaders(['User-Agent' => self::BROWSER])
            ->getJson('/api/me/tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $shown = TicketActivity::where('kind', TicketActivity::QR_IN_APP)->get();

        $this->assertCount(2, $shown);
        $this->assertEqualsCanonicalizing(Ticket::where('order_id', $order->id)->pluck('id')->all(), $shown->pluck('ticket_id')->all());
        $this->assertSame([self::ADDRESS], $shown->pluck('ip_address')->unique()->values()->all());
        $this->assertSame([$order->id], $shown->pluck('order_id')->unique()->values()->all());

        // Coming back to the front a minute later is the same look.
        $this->withServerVariables(['REMOTE_ADDR' => self::ADDRESS])
            ->withHeaders(['User-Agent' => self::BROWSER])
            ->getJson('/api/me/tickets')
            ->assertOk();

        $this->assertSame(2, TicketActivity::where('kind', TicketActivity::QR_IN_APP)->count());
    }

    public function test_a_ticket_passed_on_is_shown_in_its_new_holders_app_without_their_address(): void
    {
        $order = $this->paidOrder();
        $buyer = User::where('email', 'ada@example.com')->firstOrFail();
        [$given, $kept] = Ticket::where('order_id', $order->id)->orderBy('id')->get()->all();

        Sanctum::actingAs($buyer, [TokenAbility::Attendee->value]);
        $this->postJson("/api/tickets/{$given->id}/transfer", ['email' => 'friend@example.com', 'name' => 'Chidi'])->assertOk();

        // The friend opens the app, from their own phone, now and again.
        Sanctum::actingAs(User::where('email', 'friend@example.com')->firstOrFail(), [TokenAbility::Attendee->value]);
        foreach (['203.0.113.77', '203.0.113.78'] as $from) {
            $this->withServerVariables(['REMOTE_ADDR' => $from])
                ->withHeaders(['User-Agent' => 'FriendPhone/1.0'])
                ->getJson('/api/me/tickets')
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }

        // The buyer opens theirs.
        Sanctum::actingAs($buyer, [TokenAbility::Attendee->value]);
        $this->withServerVariables(['REMOTE_ADDR' => self::ADDRESS])
            ->withHeaders(['User-Agent' => self::BROWSER])
            ->getJson('/api/me/tickets')
            ->assertOk();

        // The ticket passed on: shown, once, and nothing about where.
        $theirs = TicketActivity::where('kind', TicketActivity::QR_IN_APP)->where('ticket_id', $given->id)->sole();
        $this->assertSame($order->id, $theirs->order_id);
        $this->assertNull($theirs->ip_address);
        $this->assertNull($theirs->user_agent);

        // The buyer's own: shown, from the buyer's phone.
        $own = TicketActivity::where('kind', TicketActivity::QR_IN_APP)->where('ticket_id', $kept->id)->sole();
        $this->assertSame(self::ADDRESS, $own->ip_address);
        $this->assertSame(self::BROWSER, $own->user_agent);

        $this->assertStringNotContainsString('203.0.113.7', (string) json_encode(DB::table('ticket_activity')->get()));
        $this->assertStringNotContainsString('FriendPhone', (string) json_encode(DB::table('ticket_activity')->get()));
    }

    public function test_a_night_past_the_dispute_window_gets_no_new_history(): void
    {
        $order = $this->paidOrder();
        $holder = User::where('email', 'ada@example.com')->firstOrFail();
        $before = TicketActivity::count();

        // The night was two years ago: no bank will hear a dispute on it.
        DB::table('events')->where('id', $order->event_id)->update([
            'starts_at' => now()->subYears(2),
            'ends_at' => now()->subYears(2)->addHours(5),
        ]);

        $this->open("/api/tickets/{$order->access_token}")->assertOk();
        $this->open("/api/tickets/{$order->access_token}/calendar.ics")->assertOk();
        $this->open(URL::signedRoute('orders.show', $order))->assertOk();
        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);
        $this->getJson('/api/me/tickets')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame($before, TicketActivity::count());
    }

    public function test_a_transfer_points_at_its_own_record_rather_than_copying_it(): void
    {
        $order = $this->paidOrder();
        $holder = User::where('email', 'ada@example.com')->firstOrFail();
        $ticket = Ticket::where('order_id', $order->id)->firstOrFail();

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);

        $this->postJson("/api/tickets/{$ticket->id}/transfer", ['email' => 'friend@example.com', 'name' => 'Chidi'])->assertOk();

        $transferred = TicketActivity::where('kind', TicketActivity::TRANSFERRED)->sole();

        $this->assertSame(TicketTransfer::sole()->id, $transferred->ticket_transfer_id);
        $this->assertSame($ticket->id, $transferred->ticket_id);
        $this->assertSame($order->id, $transferred->order_id);
        $this->assertNull($transferred->recipient_email);
        $this->assertNull($transferred->ip_address);
    }

    public function test_the_history_can_be_added_to_and_never_changed(): void
    {
        $order = $this->paidOrder();
        $this->open("/api/tickets/{$order->access_token}")->assertOk();

        foreach ([
            fn () => DB::table('ticket_activity')->where('order_id', $order->id)->update(['ip_address' => '192.0.2.1']),
            fn () => DB::table('ticket_activity')->where('order_id', $order->id)->update(['occurred_at' => now()->subYear()]),
            fn () => DB::table('ticket_activity')->where('order_id', $order->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database let the ticket history be rewritten.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('ticket_activity', $e->getMessage());
            }
        }

        $this->assertSame(self::ADDRESS, $this->history($order, TicketActivity::TICKET_PAGE)->sole()->ip_address);
    }

    public function test_no_ticket_code_is_ever_written_into_the_history(): void
    {
        $order = $this->paidOrder();
        $holder = User::where('email', 'ada@example.com')->firstOrFail();

        $this->open("/api/tickets/{$order->access_token}")->assertOk();
        $this->open("/api/tickets/{$order->access_token}/calendar.ics")->assertOk();
        $this->open(URL::signedRoute('orders.show', $order))->assertOk();
        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);
        $this->getJson('/api/me/tickets')->assertOk();

        $everything = (string) json_encode(DB::table('ticket_activity')->get());

        foreach (Ticket::pluck('code') as $code) {
            $this->assertStringNotContainsString($code, $everything);
        }

        $this->assertStringNotContainsString($order->access_token, $everything);
    }

    public function test_the_processor_is_never_asked_anything_by_any_of_it(): void
    {
        $order = $this->paidOrder();

        $this->open("/api/tickets/{$order->access_token}")->assertOk();

        $this->assertSame([], collect($this->sentToProcessor)
            ->reject(fn (ClientRequest $request) => str_ends_with($request->url(), '/v1/checkout/sessions'))
            ->map(fn (ClientRequest $request) => $request->url())
            ->values()
            ->all());
    }
}
