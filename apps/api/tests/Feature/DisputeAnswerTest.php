<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Mail\DisputeOpened;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\Disputes\ActivityLog;
use App\Services\Disputes\CaseFile;
use App\Services\Disputes\CompellingEvidence;
use App\Services\Disputes\DisputeDesk;
use App\Services\Disputes\EvidenceDocuments;
use App\Services\Disputes\EvidenceDraft;
use App\Services\Door\CheckInService;
use App\Services\Door\DoorPasses;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\OpensDisputes;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * The answer to a dispute, put together from the records the moment it opens.
 *
 * What is being protected: that each kind of dispute is answered with the
 * evidence that wins that kind — the bank's own authentication for a fraud
 * claim, delivery and entry for "never received", the policy as shown for "no
 * refund" — that every sentence of it comes from a record, that Visa's
 * Compelling Evidence 3.0 is claimed only when our records can establish it,
 * and that no ticket code reaches a bank, in a field or in a document.
 */
class DisputeAnswerTest extends TestCase
{
    use OpensDisputes, RefreshDatabase, SellsTicketsForDisputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();
    }

    // --- when it opens ------------------------------------------------------------

    public function test_a_new_dispute_is_asked_about_put_together_and_admin_and_finance_are_told_once(): void
    {
        Mail::fake();

        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        $finance = $this->staffMember(PlatformRole::Finance, 'Femi Finance');
        $support = $this->staffMember(PlatformRole::Support, 'Sade Support');

        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent');

        // Stripe was asked, and what it said kept.
        $this->assertCount(1, $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'GET'));
        $this->assertSame('needs_response', $dispute->processor_status);
        $this->assertSame('10.4', $dispute->network_reason_code);
        $this->assertNotNull($dispute->processor_checked_at);
        $this->assertSame('visa', $dispute->evidence->processor['card']['brand']);

        // The answer is ready to read, and nothing was sent anywhere.
        $this->assertNotNull($dispute->evidence);
        $this->assertNull($dispute->response);
        $this->assertCount(0, $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST'));
        $this->assertCount(0, $this->sentTo('files.stripe.com'));

        // Admin and Finance, never Support, never the buyer.
        Mail::assertQueued(DisputeOpened::class, 2);
        Mail::assertQueued(DisputeOpened::class, fn (DisputeOpened $mail) => $mail->hasTo($admin->email));
        Mail::assertQueued(DisputeOpened::class, fn (DisputeOpened $mail) => $mail->hasTo($finance->email));
        Mail::assertNotQueued(DisputeOpened::class, fn (DisputeOpened $mail) => $mail->hasTo($support->email) || $mail->hasTo('ada@example.com'));

        $mail = Mail::queued(DisputeOpened::class)->first();
        $this->assertSame($order->reference, $mail->reference);
        $this->assertSame(Money::of($order->total_amount, 'CAD')->format(), $mail->amount);
        $this->assertStringContainsString('/admin/disputes/'.$dispute->id, $mail->link);

        // The same dispute announced again: asked again, told nobody twice,
        // and the draft left as it was.
        $built = $dispute->evidence->built_at;
        $this->stripeDisputeNotice($this->stripeDisputes[$dispute->gateway_reference])->assertOk();

        Mail::assertQueued(DisputeOpened::class, 2);
        $this->assertTrue($dispute->evidence()->first()->built_at->equalTo($built));
        $this->assertSame(1, Dispute::count());
    }

    public function test_the_staff_email_is_not_written_into_the_buyers_ticket_history(): void
    {
        $this->staffMember(PlatformRole::Admin, 'Amara Admin');

        [$order, , $charge] = $this->paidOnStripe();
        $emailsBefore = TicketActivity::query()->where('kind', TicketActivity::EMAILED)->count();

        $this->disputeOnStripe($order, $charge, 'fraudulent');

        $this->assertSame($emailsBefore, TicketActivity::query()->where('kind', TicketActivity::EMAILED)->count());
    }

    public function test_a_processor_that_will_not_answer_still_leaves_a_draft_from_our_records(): void
    {
        Mail::fake();
        $this->staffMember(PlatformRole::Admin, 'Amara Admin');

        [$order, , $charge] = $this->paidOnStripe();
        $this->stripeAnswersDisputes();
        $this->processor = ['api.stripe.com/v1/disputes/*' => fn () => Http::response(['error' => ['message' => 'Down']], 500)] + $this->processor;

        $this->stripeDisputeNotice($this->stripeDispute($order, $charge, 'product_not_received'))->assertOk();

        $dispute = Dispute::sole();
        $this->assertNotNull($dispute->evidence);
        $this->assertSame('not_received', $dispute->evidence->kind);
        $this->assertNull($dispute->processor_checked_at);
        Mail::assertQueued(DisputeOpened::class, 1);
    }

    // --- by reason ------------------------------------------------------------------

    public function test_a_fraud_claim_on_a_payment_the_bank_authenticated_leads_with_the_authentication(): void
    {
        [$order, , $charge] = $this->paidOnStripe();

        // The buyer opened their tickets from the phone they bought on.
        $this->withServerVariables(['REMOTE_ADDR' => self::BUYER_ADDRESS])->withHeaders(['User-Agent' => self::BROWSER])
            ->get("/api/tickets/{$order->access_token}")->assertOk();

        $evidence = $this->disputeOnStripe($order, $charge, 'fraudulent')->evidence;
        $fields = $evidence->fields;

        $this->assertSame('fraud', $evidence->kind);
        $this->assertSame(self::BUYER_ADDRESS, $fields['customer_purchase_ip']);
        $this->assertSame('Ada Okafor', $fields['customer_name']);
        $this->assertSame('ada@example.com', $fields['customer_email_address']);
        $this->assertStringContainsString('Tickets to Afro Fest', $fields['product_description']);

        $summary = $fields['uncategorized_text'];
        $this->assertStringContainsString('Visa ending 4242', $summary);
        $this->assertStringContainsString('The card\'s bank authenticated the cardholder with 3D Secure: authenticated (3D Secure 2.2.0, challenge, ECI 05)', $summary);
        $this->assertStringContainsString('Card checks: CVC pass, postcode pass', $summary);
        $this->assertStringContainsString('Stripe\'s fraud checks: risk normal (score 32)', $summary);
        $this->assertStringContainsString('"MYFIESTA* AFRO FEST"', $summary);
        $this->assertStringContainsString('from internet address '.self::BUYER_ADDRESS, $summary);
        $this->assertStringContainsString('including from the internet address the order came from', $summary);

        // In the order it happened.
        $this->assertLessThan(strpos($summary, 'ticket(s) issued'), strpos($summary, 'placed the order online'));

        $this->assertStringContainsString('ticket page opened | IP '.self::BUYER_ADDRESS, $fields['access_activity_log']);

        $found = collect($evidence->checklist)->keyBy('key');
        $this->assertTrue($found['three_d_secure']['found']);
        $this->assertTrue($found['card_checks']['found']);
        $this->assertTrue($found['risk']['found']);
        $this->assertTrue($found['purchase_ip']['found']);
        $this->assertTrue($found['opened']['found']);
        $this->assertSame([], $evidence->cautions);

        // No refund policy for a fraud claim; the receipt, delivery and emails.
        $this->assertSame(['receipt', 'service_documentation', 'customer_communication'], $evidence->files);
        $this->assertArrayNotHasKey('refund_policy_disclosure', $fields);

        // Stripe did not list Compelling Evidence 3.0, so it is not assessed.
        $this->assertNull($evidence->compelling_evidence);

        $this->assertNoTicketCode($order, json_encode($evidence->getAttributes()));
    }

    public function test_never_received_on_a_ticket_scanned_in_is_answered_with_delivery_entry_and_the_night(): void
    {
        [$event, $type] = $this->night('CAD', [
            'starts_at' => now()->addDays(10)->setTime(22, 0),
            'ends_at' => now()->addDays(11)->setTime(3, 0),
        ]);
        [$order, , $charge] = $this->paidOnStripe($event, $type);

        $this->withServerVariables(['REMOTE_ADDR' => self::BUYER_ADDRESS])->withHeaders(['User-Agent' => self::BROWSER])
            ->get("/api/tickets/{$order->access_token}")->assertOk();

        [$first, $second] = Ticket::query()->where('order_id', $order->id)->orderBy('id')->get()->all();

        // The second ticket was passed to a friend before the night.
        TicketTransfer::create([
            'ticket_id' => $second->id,
            'from_email' => 'ada@example.com',
            'to_email' => 'chidi@example.com',
            'transferred_at' => now(),
        ]);

        $door = $this->doorStaff($event, 'Musa Bello');

        $this->travelTo($event->starts_at->copy()->addMinutes(20));
        app(CheckInService::class)->scan($first->code, $event->id, $door);
        app(CheckInService::class)->scan($second->code, $event->id, $door);

        $this->travelTo($event->ends_at->copy()->addHours(DoorPasses::GRACE_HOURS + config('disputes.completion.after_door_closes_hours'))->addMinute());
        $this->artisan('disputes:record-completions')->assertSuccessful();

        $this->travel(3)->weeks();
        $dispute = $this->disputeOnStripe($order, $charge, 'product_not_received');
        $evidence = $dispute->evidence;
        $fields = $evidence->fields;

        $this->assertSame('not_received', $evidence->kind);
        // In the night's own zone, as the buyer would have read it.
        $this->assertStringStartsWith($event->starts_at->setTimezone('America/Toronto')->format('D j M Y, H:i'), $fields['service_date']);
        $this->assertStringEndsWith('(America/Toronto)', $fields['service_date']);
        $this->assertArrayNotHasKey('customer_purchase_ip', $fields);

        $this->assertStringContainsString('door scan of ticket General · ••••', $fields['access_activity_log']);
        $this->assertStringContainsString('let in | by Musa Bello', $fields['access_activity_log']);
        $this->assertStringContainsString('ticket page opened', $fields['access_activity_log']);

        $summary = $fields['uncategorized_text'];
        $this->assertStringContainsString('2 ticket(s) issued', $summary);
        $this->assertStringContainsString('tickets emailed to ada@example.com', $summary);
        $this->assertStringContainsString('1 ticket(s) passed on by the buyer to somebody else', $summary);
        $this->assertStringContainsString('2 of 2 ticket(s) scanned in at the door (first scan by Musa Bello)', $summary);
        $this->assertStringContainsString('the night was recorded as having taken place', $summary);
        $this->assertStringContainsString('2 people let in on 2 ticket(s) issued', $summary);

        $found = collect($evidence->checklist)->keyBy('key');
        foreach (['issued', 'emailed', 'opened', 'scanned', 'completion'] as $key) {
            $this->assertTrue($found[$key]['found'], "{$key} was not found");
        }

        $this->assertContains('service_documentation', $evidence->files);
        $this->assertContains('customer_communication', $evidence->files);

        // The delivery document: who let them in, and the night.
        $html = app(EvidenceDocuments::class)->html(CaseFile::for($dispute), 'service_documentation');
        $this->assertStringContainsString('Musa Bello', $html);
        $this->assertStringContainsString('let in', $html);
        $this->assertStringContainsString('People let in', $html);
        $this->assertStringContainsString('passed on to c•••@example.com', $html);
        $this->assertStringNotContainsString('chidi@example.com', $html);

        $this->assertNoTicketCode($order, json_encode($evidence->getAttributes()).$html);
    }

    public function test_the_page_that_waits_for_the_payment_is_not_the_tickets_being_opened(): void
    {
        // Nothing delivered: the mail never left, so no email is on record.
        Mail::fake();

        [$order, , $charge] = $this->paidOnStripe();

        // Back from the payment page, waiting on the status, from the phone
        // the order was placed on.
        foreach (range(1, 3) as $poll) {
            $this->withServerVariables(['REMOTE_ADDR' => self::BUYER_ADDRESS])->withHeaders(['User-Agent' => self::BROWSER])
                ->get("/api/orders/{$order->reference}")->assertOk();
        }

        $evidence = $this->disputeOnStripe($order, $charge, 'product_not_received')->evidence;

        $this->assertStringNotContainsString('opened', $evidence->fields['uncategorized_text']);
        $this->assertStringNotContainsString('order page', (string) ($evidence->fields['access_activity_log'] ?? ''));
        $this->assertFalse(collect($evidence->checklist)->firstWhere('key', 'opened')['found']);

        // And staff are still told the buyer may well be right.
        $this->assertContains('Nothing on record shows the buyer received or used the tickets.', $evidence->cautions);
    }

    public function test_where_somebody_the_ticket_was_passed_on_to_opened_it_never_reaches_the_bank(): void
    {
        [$order, , $charge] = $this->paidOnStripe();
        $buyer = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $given = Ticket::query()->where('order_id', $order->id)->orderBy('id')->firstOrFail();

        Sanctum::actingAs($buyer, [TokenAbility::Attendee->value]);
        $this->postJson("/api/tickets/{$given->id}/transfer", ['email' => 'friend@example.com', 'name' => 'Chidi'])->assertOk();

        // The friend's phone shows it.
        Sanctum::actingAs(User::query()->where('email', 'friend@example.com')->firstOrFail(), [TokenAbility::Attendee->value]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->withHeaders(['User-Agent' => 'FriendPhone/1.0'])
            ->getJson('/api/me/tickets')->assertOk();

        // A row that did keep their address, as one written before that was
        // settled would have: still not the buyer's, so still not sent.
        $this->travel(1)->minute();
        DB::table('ticket_activity')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'ticket_id' => $given->id,
            'kind' => TicketActivity::QR_IN_APP,
            'ip_address' => '203.0.113.78',
            'user_agent' => 'FriendPhone/2.0',
            'occurred_at' => now(),
        ]);

        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent');
        $fields = $dispute->evidence->fields;
        $documents = app(EvidenceDocuments::class);
        $case = CaseFile::for($dispute);

        foreach ([json_encode($fields), $documents->html($case, 'service_documentation'), $documents->html($case, 'evidence_pack')] as $text) {
            $this->assertStringNotContainsString('203.0.113.7', $text);
            $this->assertStringNotContainsString('FriendPhone', $text);
        }

        $this->assertStringContainsString('shown in the app of the person it was passed on to', $fields['access_activity_log']);
        $this->assertStringNotContainsString('first opened from', $fields['uncategorized_text']);
    }

    public function test_an_address_that_only_contains_the_buyers_is_masked_like_any_other(): void
    {
        [$order, , $charge] = $this->paidOnStripe();
        $ticket = Ticket::query()->where('order_id', $order->id)->firstOrFail();

        // A ticket sent on to granada@, and an email that went to the buyer
        // and to nada@ at once.
        app(ActivityLog::class)->emailed(null, [$ticket], 'App\\Mail\\YourTicket', 'granada@example.com', '<one@mail.test>', 'Your ticket for Afro Fest');
        app(ActivityLog::class)->emailed($order, [], 'App\\Mail\\TicketsResent', 'ada@example.com, nada@example.com', '<two@mail.test>', 'Your tickets for Afro Fest');

        $dispute = $this->disputeOnStripe($order, $charge, 'product_not_received');
        $documents = app(EvidenceDocuments::class);
        $case = CaseFile::for($dispute);

        foreach (['service_documentation', 'evidence_pack'] as $kind) {
            $html = $documents->html($case, $kind);

            // Neither address in full (and granada@ holds nada@ within it).
            $this->assertStringNotContainsString('nada@example.com', $html);
            $this->assertStringContainsString('sent to another holder, g•••@example.com', $html);
            $this->assertStringContainsString('sent to ada@example.com; another holder, n•••@example.com', $html);
        }

        // The mixed one did reach the buyer, and counts as theirs.
        $this->assertTrue($case->emailsToBuyer()->contains('message_id', '<two@mail.test>'));
        $this->assertFalse($case->emailsToBuyer()->contains('message_id', '<one@mail.test>'));
    }

    public function test_a_night_edited_after_it_was_written_down_is_described_as_it_took_place(): void
    {
        [$event, $type] = $this->night('CAD', [
            'starts_at' => now()->addDays(10)->setTime(22, 0),
            'ends_at' => now()->addDays(11)->setTime(3, 0),
        ]);
        [$order, , $charge] = $this->paidOnStripe($event, $type);
        $ticket = Ticket::query()->where('order_id', $order->id)->firstOrFail();

        $this->travelTo($event->starts_at->copy()->addMinutes(20));
        app(CheckInService::class)->scan($ticket->code, $event->id, $this->doorStaff($event, 'Musa Bello'));

        $this->travelTo($event->ends_at->copy()->addHours(DoorPasses::GRACE_HOURS + config('disputes.completion.after_door_closes_hours'))->addMinute());
        $this->artisan('disputes:record-completions')->assertSuccessful();

        // The organizer renames the night and moves it two months on.
        Event::query()->whereKey($event->id)->update([
            'title' => 'Afro Fest: Winter Edition',
            'starts_at' => now()->addMonths(2),
            'ends_at' => now()->addMonths(2)->addHours(5),
        ]);

        $fields = $this->disputeOnStripe($order, $charge, 'credit_not_processed')->evidence->fields;
        $took = $event->starts_at->copy()->setTimezone('America/Toronto')->format('D j M Y, H:i');

        $this->assertStringStartsWith($took, $fields['service_date']);
        $this->assertStringContainsString('Tickets to Afro Fest, a live event on '.$took, $fields['product_description']);
        $this->assertStringNotContainsString('Winter Edition', (string) json_encode($fields));

        // It happened, so no refund was due for it not happening yet.
        $this->assertStringContainsString('Afro Fest was not cancelled.', $fields['refund_refusal_explanation']);
        $this->assertStringNotContainsString('takes place on', $fields['refund_refusal_explanation']);
    }

    public function test_refund_not_received_is_answered_with_the_policy_as_it_was_shown_and_accepted(): void
    {
        // Stripe's own terms box was asked for and ticked.
        [$order, , $charge] = $this->paidOnStripe(session: [
            'consent_collection' => ['terms_of_service' => 'required'],
            'consent' => ['terms_of_service' => 'accepted', 'promotions' => null],
        ]);

        $dispute = $this->disputeOnStripe($order, $charge, 'credit_not_processed');
        $evidence = $dispute->evidence;
        $fields = $evidence->fields;
        $summary = (string) file_get_contents(resource_path('legal/2026-09-27.2/refund-summary.txt'));

        $this->assertSame('refund', $evidence->kind);
        $this->assertContains('refund_policy', $evidence->files);

        $disclosure = $fields['refund_policy_disclosure'];
        $this->assertStringContainsString('"'.EvidenceDraft::CHECKBOX.'"', $disclosure);
        $this->assertStringContainsString('unticked to begin with', $disclosure);
        $this->assertStringContainsString(trim($summary), $disclosure);
        $this->assertStringContainsString('beside the pay button on Stripe\'s payment page', $disclosure);
        $this->assertStringContainsString('accepted version 2026-09-27.2', $disclosure);
        $this->assertStringContainsString(CaseFile::at($order->terms_accepted_at), $disclosure);
        $this->assertStringContainsString('Stripe also recorded that the buyer ticked Stripe\'s own terms of service box', $disclosure);
        $this->assertStringContainsString('https://myfiesta.test/refunds', $disclosure);

        $refusal = $fields['refund_refusal_explanation'];
        $this->assertStringContainsString(trim($summary), $refusal);
        $this->assertStringContainsString('has not been cancelled', $refusal);
        $this->assertStringContainsString('No refund was made through myFiesta for this order.', $refusal);

        // The policy document says what the buyer's version said.
        $html = app(EvidenceDocuments::class)->html(CaseFile::for($dispute), 'refund_policy');
        $this->assertStringContainsString('Who decides', $html);
        $this->assertStringContainsString('Version 2026-09-27.2', $html);
        $this->assertStringContainsString(e(EvidenceDraft::CHECKBOX), $html);
        $this->assertStringContainsString('Ticked on Stripe', $html);
    }

    public function test_a_cancelled_night_that_was_never_refunded_says_the_buyer_is_owed_one_and_claims_nothing_else(): void
    {
        [$order, , $charge] = $this->paidOnStripe();
        Event::query()->whereKey($order->event_id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $evidence = $this->disputeOnStripe($order, $charge, 'credit_not_processed')->evidence;

        $this->assertStringContainsString('The night was cancelled and the order was not refunded in full', implode(' ', $evidence->cautions));
        $this->assertArrayNotHasKey('refund_refusal_explanation', array_filter($evidence->fields));
    }

    public function test_charged_twice_names_the_other_order_and_its_charge(): void
    {
        [$event, $type] = $this->night();
        [$first, , $firstCharge] = $this->paidOnStripe($event, $type);
        [$second, , $secondCharge] = $this->paidOnStripe($event, $type);

        $fields = $this->disputeOnStripe($second, $secondCharge, 'duplicate')->evidence->fields;

        $this->assertSame($firstCharge, $fields['duplicate_charge_id']);
        $this->assertStringContainsString('order '.$first->reference, $fields['duplicate_charge_explanation']);
        $this->assertStringContainsString('order '.$second->reference, $fields['duplicate_charge_explanation']);
    }

    // --- Visa Compelling Evidence 3.0 ---------------------------------------------

    public function test_compelling_evidence_is_sent_when_stripe_lists_it_and_the_records_establish_it(): void
    {
        // Orders placed on an account, which no checkout records today: this
        // is the path a signed-in checkout would take, and it must be right
        // the day one does.
        $buyer = User::factory()->create(['email' => 'ada@example.com']);

        // Two earlier payments on the same card, from the same account and
        // address, 200 and 150 days before.
        $prior = [];
        foreach ([200, 150] as $daysAgo) {
            $this->travelTo(now()->subDays($daysAgo));
            [$order, , $charge] = $this->paidOnStripe();
            $order->forceFill(['user_id' => $buyer->id])->save();
            $prior[] = $charge;
            $this->travelBack();
        }

        [$order, , $charge] = $this->paidOnStripe();
        $order->forceFill(['user_id' => $buyer->id])->save();

        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent', [
            'enhanced_eligibility_types' => [CompellingEvidence::TYPE],
            'evidence_details' => ['enhanced_eligibility' => [CompellingEvidence::TYPE => [
                'required_actions' => ['missing_prior_undisputed_transactions'],
                'status' => 'requires_action',
            ]]],
        ]);

        $assessment = $dispute->evidence->compelling_evidence;
        $this->assertTrue($assessment['eligible']);
        $this->assertSame('requires_action', $assessment['stripe_status']);
        $this->assertEqualsCanonicalizing($prior, array_column($assessment['prior'], 'charge'));
        $this->assertSame($buyer->id, $assessment['disputed']['customer_account_id']);
        $this->assertSame(self::BUYER_ADDRESS, $assessment['disputed']['customer_purchase_ip']);
        $this->assertSame('services', $assessment['disputed']['merchandise_or_services']);
        $this->assertTrue(collect($dispute->evidence->checklist)->firstWhere('key', 'compelling_evidence')['found']);

        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        app(DisputeDesk::class)->submit($dispute, $admin);

        $sent = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST')[0]->data();
        $ce3 = $sent['evidence']['enhanced_evidence'][CompellingEvidence::TYPE];

        $this->assertSame($buyer->id, $ce3['disputed_transaction']['customer_account_id']);
        $this->assertSame('ada@example.com', $ce3['disputed_transaction']['customer_email_address']);
        $this->assertSame(self::BUYER_ADDRESS, $ce3['disputed_transaction']['customer_purchase_ip']);
        $this->assertSame('services', $ce3['disputed_transaction']['merchandise_or_services']);
        $this->assertCount(2, $ce3['prior_undisputed_transactions']);
        $this->assertEqualsCanonicalizing($prior, array_column($ce3['prior_undisputed_transactions'], 'charge'));
        // What the page shows beside each is not sent.
        $this->assertArrayNotHasKey('reference', $ce3['prior_undisputed_transactions'][0]);
        $this->assertArrayNotHasKey('customer_device_fingerprint', $ce3['disputed_transaction']);
    }

    public function test_an_order_placed_through_checkout_cannot_qualify_and_the_page_says_why_and_nothing_is_claimed(): void
    {
        // The buyer has an account — the one their tickets are held in — but
        // checkout reads no sign-in, so no order is placed on it.
        foreach ([200, 150] as $daysAgo) {
            $this->travelTo(now()->subDays($daysAgo));
            $this->paidOnStripe();
            $this->travelBack();
        }

        [$order, , $charge] = $this->paidOnStripe();
        $this->assertNotNull(User::query()->where('email', 'ada@example.com')->first());
        $this->assertNull($order->user_id);

        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent', ['enhanced_eligibility_types' => [CompellingEvidence::TYPE]]);

        $assessment = $dispute->evidence->compelling_evidence;
        $this->assertFalse($assessment['eligible']);
        $this->assertStringContainsString('not placed signed in to a myFiesta account', $assessment['why']);
        $this->assertStringContainsString('does not record one on any order today', $assessment['why']);
        $this->assertStringNotContainsString('guest', $assessment['why']);
        $this->assertStringContainsString('ordinary evidence instead', $assessment['why']);
        $this->assertNull($assessment['disputed']);

        app(DisputeDesk::class)->submit($dispute, $this->staffMember(PlatformRole::Finance, 'Femi Finance'));

        $sent = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST')[0]->data();
        $this->assertArrayNotHasKey('enhanced_evidence', $sent['evidence']);
    }

    public function test_compelling_evidence_is_not_even_assessed_when_stripe_does_not_list_it(): void
    {
        $buyer = User::factory()->create(['email' => 'ada@example.com']);

        foreach ([200, 150] as $daysAgo) {
            $this->travelTo(now()->subDays($daysAgo));
            [$earlier] = $this->paidOnStripe();
            $earlier->forceFill(['user_id' => $buyer->id])->save();
            $this->travelBack();
        }

        [$order, , $charge] = $this->paidOnStripe();
        $order->forceFill(['user_id' => $buyer->id])->save();
        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent');

        $this->assertNull($dispute->evidence->compelling_evidence);

        app(DisputeDesk::class)->submit($dispute, $this->staffMember(PlatformRole::Admin, 'Amara Admin'));

        $this->assertArrayNotHasKey('enhanced_evidence', $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST')[0]->data()['evidence']);
    }

    // --- the documents ----------------------------------------------------------

    public function test_every_document_renders_as_a_pdf_and_none_carries_a_ticket_code(): void
    {
        [$event, $type] = $this->night('CAD', ['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(5)]);
        [$order, , $charge] = $this->paidOnStripe($event, $type);

        $ticket = Ticket::query()->where('order_id', $order->id)->firstOrFail();
        $this->travelTo($event->starts_at->copy()->addMinutes(5));
        app(CheckInService::class)->scan($ticket->code, $event->id, $this->doorStaff($event, 'Musa Bello'));
        // A code that matched nothing is kept by the door as it was scanned,
        // and must not find its way out either.
        app(CheckInService::class)->scan($ticket->code, $event->id);

        $dispute = $this->disputeOnStripe($order, $charge, 'general');
        $case = CaseFile::for($dispute);
        $documents = app(EvidenceDocuments::class);

        foreach (array_keys(EvidenceDraft::DOCUMENTS) as $kind) {
            $html = $documents->html($case, $kind);
            $this->assertNoTicketCode($order, $html);
            $this->assertStringContainsString($order->reference, $html);

            $file = $documents->render($case, $kind);
            $this->assertStringStartsWith('%PDF-', $file->bytes, "{$kind} is not a PDF.");
            $this->assertGreaterThan(1000, $file->size());
            $this->assertStringEndsWith('-'.$order->reference.'.pdf', $file->name);
        }

        $receipt = $documents->html($case, 'receipt');
        $this->assertStringContainsString(Money::of($order->total_amount, 'CAD')->format(), $receipt);
        $this->assertStringContainsString('Visa ending 4242', $receipt);
        $this->assertStringContainsString('MYFIESTA* AFRO FEST', $receipt);

        $emails = $documents->html($case, 'customer_communication');
        $this->assertStringContainsString('Your tickets for Afro Fest', $emails);
        $this->assertStringContainsString('Order confirmation, with the tickets', $emails);
    }

    public function test_the_terms_box_the_evidence_quotes_says_what_the_sites_checkout_says(): void
    {
        $path = __DIR__.'/../../../web/src/app/features/checkout/checkout.html';

        if (! file_exists($path)) {
            $this->markTestSkipped('The public site is not checked out beside the API.');
        }

        $words = fn (string $text) => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text))));

        $this->assertStringContainsString(
            $words(EvidenceDraft::CHECKBOX),
            $words((string) file_get_contents($path)),
            'The dispute evidence quotes a terms box the checkout no longer shows. Change EvidenceDraft::CHECKBOX with it.',
        );
    }

    // --- helpers ------------------------------------------------------------------

    private function doorStaff(Event $event, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);

        $event->organization->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Door->value,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function assertNoTicketCode(Order $order, string $text): void
    {
        foreach ($this->ticketCodes($order) as $code) {
            $this->assertStringNotContainsString($code, $text, 'A ticket code reached the dispute evidence.');
        }
    }
}
