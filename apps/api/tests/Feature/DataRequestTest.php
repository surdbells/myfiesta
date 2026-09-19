<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\DataRequestDone;
use App\Mail\DataRequestVerify;
use App\Models\AuditLog;
use App\Models\DataRequest;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Somebody asking what is held about them, or asking to be forgotten.
 *
 * What is being protected: that neither happens to anybody who did not ask —
 * the link to the address is the proof, and a GET never acts; that an export
 * hands over what is theirs and not a credential or somebody else's row; and
 * that erasure says truthfully what it did, because the financial records it
 * cannot delete are the part people would be angriest to discover later.
 */
class DataRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('private');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);

        TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 5000, 'status' => 'on_sale']);
    }

    private function buyer(string $email = 'ada@example.com'): Order
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'reference' => strtoupper(Str::random(10)),
            'buyer_email' => $email,
            'buyer_name' => 'Ada Okafor',
            'buyer_phone' => '+15551234567',
            'currency' => 'CAD',
            'subtotal_amount' => 5000,
            'total_amount' => 5000,
            'net_revenue_amount' => 5000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Ticket::create([
            'event_id' => $this->event->id,
            'ticket_type_id' => $this->event->ticketTypes()->value('id'),
            'order_id' => $order->id,
            'owner_email' => $email,
            'holder_name' => 'Ada Okafor',
            'code' => $email === 'ada@example.com' ? 'SECRETCODE12' : strtoupper(Str::random(12)),
            'status' => 'valid',
        ]);

        LedgerEntry::create([
            'organization_id' => $this->org->id,
            'event_id' => $this->event->id,
            'order_id' => $order->id,
            'type' => 'sale',
            'amount' => 5000,
            'currency' => 'CAD',
            'occurred_at' => now(),
            'reason' => "Order {$order->reference}",
        ]);

        return $order;
    }

    private function ask(string $kind, string $email = 'ada@example.com'): DataRequest
    {
        $this->postJson('/api/privacy/requests', ['kind' => $kind, 'email' => $email])->assertStatus(202);

        // By id, not by time: two requests made in the same second are a
        // coin toss to latest(), and uuid7 ids sort the way they were made.
        return DataRequest::where('email', strtolower($email))->orderByDesc('id')->firstOrFail();
    }

    // --- proving it is you ---------------------------------------------------------

    public function test_the_answer_is_the_same_whoever_asked(): void
    {
        $this->buyer();

        $known = $this->postJson('/api/privacy/requests', ['kind' => 'export', 'email' => 'ada@example.com'])->assertStatus(202);
        $stranger = $this->postJson('/api/privacy/requests', ['kind' => 'export', 'email' => 'nobody@example.com'])->assertStatus(202);

        // Word for word: anything else is a way to ask whether somebody has an
        // account here.
        $this->assertSame($known->json('message'), $stranger->json('message'));

        // But only the address we actually hold something about is written to.
        Mail::assertQueued(DataRequestVerify::class, 1);
        Mail::assertQueued(DataRequestVerify::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_opening_the_link_does_nothing_until_it_is_confirmed(): void
    {
        $this->buyer();
        $request = $this->ask('erasure');

        $this->get(route('privacy.request', $request->token))
            ->assertOk()
            ->assertSee('Erase everything about you?', false);

        // Mail scanners fetch links. An erasure on GET erases people who never
        // clicked.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertNotNull(Order::sole()->buyer_email);
    }

    public function test_a_link_nobody_proved_stops_working(): void
    {
        $this->buyer();
        $request = $this->ask('export');

        $this->travel(DataRequest::VERIFY_HOURS + 1)->hours();
        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertSame('expired', $request->fresh()->status);
        $this->post(route('privacy.confirm', $request->token))->assertNotFound();
    }

    public function test_an_unknown_link_says_nothing_about_who_exists(): void
    {
        $this->get(route('privacy.request', 'not-a-real-token'))
            ->assertNotFound()
            ->assertSee('That link has expired', false)
            ->assertDontSee('no such');
    }

    // --- the export ----------------------------------------------------------------

    public function test_an_export_holds_what_is_theirs_and_no_credentials(): void
    {
        $order = $this->buyer();
        $this->buyer('somebody-else@example.com');
        WaitlistEntry::create(['event_id' => $this->event->id, 'email' => 'ada@example.com', 'quantity' => 2, 'status' => 'waiting', 'token' => Str::random(40)]);

        $request = $this->ask('export');
        $this->post(route('privacy.confirm', $request->token))->assertOk()->assertSee('Here it is', false);

        $request->refresh();
        $this->assertSame('completed', $request->status);

        $export = json_decode(Storage::disk('private')->get($request->file_path), true);
        $json = json_encode($export);

        $this->assertSame('ada@example.com', $export['about']['email']);
        $this->assertSame($order->reference, $export['data']['orders'][0]['reference']);
        $this->assertCount(1, $export['data']['orders']);
        $this->assertCount(1, $export['data']['waitlist_entries']);

        // Someone else's order is not theirs to read.
        $this->assertStringNotContainsString('somebody-else@example.com', $json);
        // Nor is anything that opens a door or an account.
        $this->assertStringNotContainsString('SECRETCODE12', $json);
        $this->assertStringNotContainsString($order->access_token, $json);
    }

    public function test_the_file_can_be_downloaded_until_it_expires(): void
    {
        $this->buyer();
        $request = $this->ask('export');
        $this->post(route('privacy.confirm', $request->token));

        $this->get(route('privacy.download', $request->token))->assertOk()->assertDownload('myfiesta-your-data.json');

        $path = $request->fresh()->file_path;
        $this->travel(DataRequest::DOWNLOAD_DAYS + 1)->days();
        $this->artisan('privacy:prune')->assertSuccessful();

        $this->assertFalse(Storage::disk('private')->exists($path));
        $this->get(route('privacy.download', $request->token))->assertNotFound();
        Mail::assertQueued(DataRequestDone::class);
    }

    // --- erasure -------------------------------------------------------------------

    public function test_erasure_clears_the_person_and_keeps_the_money(): void
    {
        $order = $this->buyer();
        WaitlistEntry::create(['event_id' => $this->event->id, 'email' => 'ada@example.com', 'quantity' => 1, 'status' => 'waiting', 'token' => Str::random(40)]);
        EmailPreference::forEmail('ada@example.com')->update(['marketing_opted_out_at' => now()]);

        $request = $this->ask('erasure');
        $this->post(route('privacy.confirm', $request->token))->assertOk()->assertSee('Done', false);

        // Gone entirely: nothing is owed to anybody on the strength of it.
        $this->assertSame(0, WaitlistEntry::count());

        // Kept, without them: the record of money that moved.
        $order->refresh();
        $this->assertSame(5000, (int) $order->total_amount);
        // A web sale must have a buyer address, so this one gets a placeholder
        // at a domain nothing can be delivered to.
        $this->assertStringEndsWith('@erased.invalid', $order->buyer_email);
        $this->assertSame('Erased', $order->buyer_name);
        $this->assertSame(1, LedgerEntry::count());

        $ticket = Ticket::sole();
        $this->assertNull($ticket->owner_email);
        $this->assertSame('valid', $ticket->status);

        // The one address kept on purpose, because forgetting it would start
        // the emails again.
        $this->assertFalse(EmailPreference::forEmail('ada@example.com')->wantsMarketing());

        $outcome = $request->fresh()->outcome['erased'];
        $this->assertSame('anonymised', $outcome['orders']['action']);
        $this->assertSame('deleted', $outcome['waitlist_entries']['action']);
        $this->assertSame('kept', $outcome['email_preferences']['action']);

        $this->assertTrue(AuditLog::where('action', 'privacy.erasure')->exists());
    }

    public function test_erasing_an_account_closes_it_and_revokes_every_token(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $token = $user->createToken('phone')->plainTextToken;
        $this->buyer();

        $request = $this->ask('erasure');
        $this->post(route('privacy.confirm', $request->token))->assertOk();

        $user->refresh();
        $this->assertNotNull($user->deleted_at);
        $this->assertStringNotContainsString('ada@example.com', $user->email);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $this->getJson('/api/me/tickets', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_the_only_owner_of_an_organization_is_refused_with_a_way_out(): void
    {
        $owner = User::factory()->create(['email' => 'ada@example.com']);
        $this->org->members()->attach($owner->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);

        $request = $this->ask('erasure');
        $this->post(route('privacy.confirm', $request->token))->assertOk()->assertSee('Not yet', false);

        $request->refresh();
        $this->assertSame('refused', $request->status);
        $this->assertStringContainsString('Lagos Nights', $request->outcome['refused']);
        $this->assertNull($owner->fresh()->deleted_at);

        // With a second owner, the same request goes through.
        $second = User::factory()->create();
        $this->org->members()->attach($second->id, ['id' => (string) Str::uuid(), 'role' => Role::Owner->value, 'accepted_at' => now()]);

        $again = $this->ask('erasure');
        $this->post(route('privacy.confirm', $again->token))->assertOk()->assertSee('Done', false);

        $this->assertNotNull($owner->fresh()->deleted_at);
    }

    public function test_the_request_itself_survives_the_erasure_it_asked_for(): void
    {
        $this->buyer();
        $request = $this->ask('erasure');
        $this->post(route('privacy.confirm', $request->token));

        // The proof that the law was obeyed cannot be erased along with the
        // person, or there is nothing to show for it but their absence.
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame('ada@example.com', $request->fresh()->email);
    }
}
