<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Disputes\DisputeDesk;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\OpensDisputes;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * Sending the answer, or conceding: once, by the right people, on the record.
 *
 * The documents go to the processor first and the fields after, naming them;
 * a second press sends nothing; a send that fails part-way sends only what
 * is missing when tried again — under the same keys when nothing came back,
 * under new ones when Stripe refused, since it gives a key its first answer
 * again; each step, refused or not, is in the audit trail without the words
 * or the files themselves; and Support, who can read every dispute, can send
 * none of them. Paystack's three steps are checked against the shapes its
 * documentation gives.
 */
class DisputeSubmissionTest extends TestCase
{
    use OpensDisputes, RefreshDatabase, SellsTicketsForDisputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        Mail::fake();
        Storage::fake(DisputeDesk::DISK);
    }

    // --- Stripe ----------------------------------------------------------------------

    public function test_submitting_uploads_the_documents_then_sends_the_evidence_once_and_is_audited(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'product_not_received');
        $files = $dispute->evidence->files;

        $said = app(DisputeDesk::class)->submit($dispute, $admin);

        $this->assertSame('Sent to Stripe. It now reads: under review.', $said);

        // Each document to the Files API, as dispute evidence, before the
        // fields are sent — and the fields name what came back.
        $uploads = $this->sentTo('files.stripe.com/v1/files', 'POST');
        $this->assertCount(count($files), $uploads);

        foreach ($uploads as $upload) {
            $this->assertTrue($upload->isMultipart());
            $this->assertSame('dispute_evidence', collect($upload->data())->firstWhere('name', 'purpose')['contents']);
            $this->assertStringStartsWith('%PDF-', (string) collect($upload->data())->firstWhere('name', 'file')['contents']);
            $this->assertNotEmpty($upload->header('Idempotency-Key'));
        }

        $posts = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST');
        $this->assertCount(1, $posts);
        $this->assertGreaterThan(
            array_search(end($uploads), $this->sentToProcessor, true),
            array_search($posts[0], $this->sentToProcessor, true),
            'The evidence was sent before its documents were uploaded.',
        );

        $body = $posts[0]->data();
        $this->assertSame('true', $body['submit']);
        $this->assertSame('Ada Okafor', $body['evidence']['customer_name']);
        $this->assertStringContainsString('ticket(s) issued', $body['evidence']['uncategorized_text']);

        $dispute->refresh();
        $handles = collect($dispute->evidence->uploads)->pluck('handle', null)->all();

        foreach ($files as $kind) {
            $this->assertStringStartsWith('file_', $body['evidence'][$kind]);
            $this->assertSame($dispute->evidence->uploads[$kind]['handle'], $body['evidence'][$kind]);
            // Kept as sent, privately.
            Storage::disk(DisputeDesk::DISK)->assertExists($dispute->evidence->uploads[$kind]['path']);
        }

        $this->assertCount(count($files), $handles);
        $this->assertSame(Dispute::SUBMITTED, $dispute->response);
        $this->assertSame($admin->id, $dispute->responded_by);
        $this->assertSame('under_review', $dispute->processor_status);
        $this->assertNotNull($dispute->evidence->submitted_at);
        $this->assertSame($body['evidence']['uncategorized_text'], $dispute->evidence->sent['fields']['uncategorized_text']);

        // Who, when, what — not the words, not the files.
        $entry = AuditLog::query()->where('action', 'dispute.evidence_submitted')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($dispute->id, $entry->subject_id);
        $this->assertSame($dispute->gateway_reference, $entry->metadata['dispute']);
        $this->assertSame(mb_strlen($body['evidence']['uncategorized_text']), $entry->metadata['fields']['uncategorized_text']);
        $this->assertCount(count($files), $entry->metadata['files']);
        $this->assertSame($dispute->evidence->uploads['receipt']['handle'], collect($entry->metadata['files'])->firstWhere('kind', 'receipt')['processor_file']);
        $this->assertSame(64, strlen(collect($entry->metadata['files'])->firstWhere('kind', 'receipt')['sha256']));
        $logged = json_encode($entry->metadata);
        $this->assertStringNotContainsString('ada@example.com', $logged);
        $this->assertStringNotContainsString('Ada Okafor', $logged);
        foreach ($this->ticketCodes($order) as $code) {
            $this->assertStringNotContainsString($code, $logged);
        }

        // Pressed again: said so, and nothing sent.
        $before = count($this->sentToProcessor);
        $again = app(DisputeDesk::class)->submit($dispute->fresh(), $admin);

        $this->assertStringContainsString('already sent', $again);
        $this->assertCount($before, $this->sentToProcessor);
        $this->assertSame(1, AuditLog::query()->where('action', 'dispute.evidence_submitted')->count());
    }

    public function test_a_send_stripe_refused_is_tried_again_under_new_keys_without_uploading_anything_twice(): void
    {
        $finance = $this->staffMember(PlatformRole::Finance, 'Femi Finance');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent');
        $files = count($dispute->evidence->files);

        $this->stripeAnswersWith = 500;

        try {
            app(DisputeDesk::class)->submit($dispute, $finance);
            $this->fail('A refused send was reported as sent.');
        } catch (StaffActionRefused $refused) {
            $this->assertStringContainsString('Stripe did not take the evidence', $refused->getMessage());
            $this->assertStringContainsString('Nothing is marked as sent', $refused->getMessage());
        }

        $dispute->refresh();
        $this->assertNull($dispute->response);
        $this->assertCount($files, $dispute->evidence->uploads);

        // The refusal is on the record, with the documents that had already
        // reached Stripe — they left, whatever became of the answer.
        $refusal = AuditLog::query()->where('action', 'dispute.evidence_not_accepted')->sole();
        $this->assertCount($files, $refusal->metadata['files']);
        $this->assertSame($dispute->evidence->uploads['receipt']['handle'], collect($refusal->metadata['files'])->firstWhere('kind', 'receipt')['processor_file']);

        // Stripe has come back, and would give the old key its old 500.
        $this->stripeAnswersWith = 200;
        app(DisputeDesk::class)->submit($dispute, $finance);

        // The documents went once; the fields went again under a new key, so
        // Stripe answered them rather than repeating its refusal.
        $this->assertCount($files, $this->sentTo('files.stripe.com/v1/files'));
        $posts = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST');
        $this->assertCount(2, $posts);
        $this->assertNotSame($posts[0]->header('Idempotency-Key'), $posts[1]->header('Idempotency-Key'));
        $this->assertSame($posts[0]->data(), $posts[1]->data());
        $this->assertSame(Dispute::SUBMITTED, $dispute->fresh()->response);
    }

    public function test_a_send_that_heard_nothing_back_asks_again_under_the_same_key(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'product_not_received');

        // The connection drops: Stripe may or may not have it.
        $this->stripeAnswersWith = 0;
        $this->expectRefusal(fn () => app(DisputeDesk::class)->submit($dispute, $admin), 'Stripe did not take the evidence');

        $this->stripeAnswersWith = 200;
        app(DisputeDesk::class)->submit($dispute->fresh(), $admin);

        // Asked under the same key, so had the first arrived, Stripe would
        // have answered with it rather than taking the evidence twice.
        $posts = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST');
        $this->assertCount(2, $posts);
        $this->assertSame($posts[0]->header('Idempotency-Key'), $posts[1]->header('Idempotency-Key'));
        $this->assertSame(Dispute::SUBMITTED, $dispute->fresh()->response);
    }

    public function test_accepting_after_stripe_refused_to_close_asks_again_under_a_new_key(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'credit_not_processed');

        $this->stripeAnswersWith = 500;
        $this->expectRefusal(fn () => app(DisputeDesk::class)->accept($dispute, $admin, 'The organizer agreed to refund.'), 'Nothing is marked as accepted');

        // Refused, and audited as refused.
        $refused = AuditLog::query()->where('action', 'dispute.accept_not_accepted')->sole();
        $this->assertSame($admin->id, $refused->actor_id);
        $this->assertStringContainsString('unknown error', $refused->metadata['error']);
        $this->assertNull($dispute->fresh()->response);

        $this->stripeAnswersWith = 200;
        app(DisputeDesk::class)->accept($dispute->fresh(), $admin, 'The organizer agreed to refund.');

        $closes = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference.'/close', 'POST');
        $this->assertCount(2, $closes);
        $this->assertNotSame($closes[0]->header('Idempotency-Key'), $closes[1]->header('Idempotency-Key'));
        $this->assertSame(Dispute::ACCEPTED, $dispute->fresh()->response);
    }

    public function test_corrected_words_after_a_refusal_go_under_a_new_key(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent');

        $this->stripeAnswersWith = 400;
        rescue(fn () => app(DisputeDesk::class)->submit($dispute, $admin), report: false);

        app(DisputeDesk::class)->saveDraft($dispute->fresh(), ['product_description' => 'Two general admission tickets to Afro Fest.'], $admin);
        $this->stripeAnswersWith = 200;
        app(DisputeDesk::class)->submit($dispute->fresh(), $admin);

        $posts = $this->sentTo('/v1/disputes/'.$dispute->gateway_reference, 'POST');
        $this->assertNotSame($posts[0]->header('Idempotency-Key'), $posts[1]->header('Idempotency-Key'));
        $this->assertSame('Two general admission tickets to Afro Fest.', $posts[1]->data()['evidence']['product_description']);
    }

    public function test_accepting_closes_it_once_and_the_loss_is_written_when_stripe_confirms(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'credit_not_processed');

        $said = app(DisputeDesk::class)->accept($dispute, $admin, 'The organizer agreed to refund and never did.');

        $this->assertStringStartsWith('Accepted.', $said);
        $this->assertCount(1, $this->sentTo('/v1/disputes/'.$dispute->gateway_reference.'/close', 'POST'));
        $this->assertCount(0, $this->sentTo('files.stripe.com'));

        $dispute->refresh();
        $this->assertSame(Dispute::ACCEPTED, $dispute->response);
        $this->assertSame('lost', $dispute->processor_status);
        // Still open on our side until Stripe says it has closed.
        $this->assertSame('open', $dispute->status);

        $entry = AuditLog::query()->where('action', 'dispute.accepted')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('The organizer agreed to refund and never did.', $entry->metadata['note']);

        // Again: nothing sent. Sending evidence now: refused.
        $this->assertStringContainsString('already accepted', app(DisputeDesk::class)->accept($dispute, $admin, 'Again.'));
        $this->assertCount(1, $this->sentTo('/close'));
        $this->expectRefusal(fn () => app(DisputeDesk::class)->submit($dispute->fresh(), $admin), 'already been accepted');

        // Stripe confirms: the chargeback is written the way any loss is.
        $this->stripeDisputeNotice(array_replace($this->stripeDisputes[$dispute->gateway_reference], ['status' => 'lost']), 'charge.dispute.closed')->assertOk();

        $this->assertSame('lost', $dispute->fresh()->status);
        $this->assertSame(1, LedgerEntry::query()->where('order_id', $order->id)->where('type', 'chargeback')->count());
        $this->assertSame(0, Ticket::query()->where('order_id', $order->id)->where('status', 'valid')->count());
    }

    public function test_support_can_read_a_dispute_but_not_answer_it_or_open_its_documents(): void
    {
        $support = $this->staffMember(PlatformRole::Support, 'Sade Support');
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, , $charge] = $this->paidOnStripe();
        $dispute = $this->disputeOnStripe($order, $charge, 'fraudulent');
        $sent = count($this->sentToProcessor);

        $this->expectRefusal(fn () => app(DisputeDesk::class)->submit($dispute, $support), 'Only Admin and Finance');
        $this->expectRefusal(fn () => app(DisputeDesk::class)->accept($dispute, $support, 'The buyer is right.'), 'Only Admin and Finance');
        $this->expectRefusal(fn () => app(DisputeDesk::class)->saveDraft($dispute, ['customer_name' => 'Somebody'], $support), 'Only Admin and Finance');

        $this->assertCount($sent, $this->sentToProcessor);
        $this->assertNull($dispute->fresh()->response);

        $url = URL::temporarySignedRoute('disputes.document', now()->addMinutes(5), ['dispute' => $dispute->id, 'file' => 'receipt']);

        $this->actingAs($support)->get($url)->assertForbidden();
        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');

        // Unsigned, or signed and run out: nobody.
        $this->actingAs($admin)->get(route('disputes.document', ['dispute' => $dispute->id, 'file' => 'receipt']))->assertForbidden();
    }

    // --- Paystack --------------------------------------------------------------------

    public function test_a_paystack_dispute_is_put_together_from_its_own_shape_and_answered_in_its_three_steps(): void
    {
        $finance = $this->staffMember(PlatformRole::Finance, 'Femi Finance');
        [$order, $dispute] = $this->disputeOnPaystack();
        $evidence = $dispute->evidence;

        $this->assertCount(1, $this->sentTo('api.paystack.co/dispute/'.$dispute->gateway_reference, 'GET'));
        $this->assertSame('awaiting-merchant-feedback', $dispute->processor_status);
        $this->assertNotNull($dispute->evidence_due_at);

        // The buyer's words kept; the card's first six never.
        $this->assertSame('I paid and never got my tickets.', $evidence->processor['messages'][0]['body']);
        $this->assertStringNotContainsString('408408', json_encode($evidence->getAttributes()));
        $this->assertStringNotContainsString('408408', (string) json_encode(Dispute::query()->whereKey($dispute->id)->first()->getAttributes()));

        // Paystack's own fields, the phone from Paystack since the order has none.
        $this->assertSame(['customer_name', 'customer_email', 'customer_phone', 'service_details', 'delivery_date', 'message'], array_keys($evidence->fields));
        $this->assertSame('08031234567', $evidence->fields['customer_phone']);
        $this->assertSame('ada@example.com', $evidence->fields['customer_email']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $evidence->fields['delivery_date']);
        $this->assertStringContainsString('ticket(s) issued', $evidence->fields['service_details']);
        $this->assertSame(['evidence_pack'], $evidence->files);

        app(DisputeDesk::class)->submit($dispute, $finance);

        $path = 'api.paystack.co/dispute/'.$dispute->gateway_reference;
        $steps = collect($this->sentToProcessor)
            ->map(function (ClientRequest $request) use ($path) {
                $url = explode('?', $request->url())[0];

                return match (true) {
                    str_contains($url, 's3.eu-west-1.amazonaws.com/files.paystack.co/disputes/') => $request->method().' the signed upload address',
                    str_contains($url, $path) => $request->method().' '.(Str::after($url, $path) ?: '/'),
                    default => null,
                };
            })
            ->filter()
            ->values()
            ->all();

        // Asked about when it opened; then evidence, the file, the answer.
        $this->assertSame(['GET /', 'POST /evidence', 'GET /upload_url', 'PUT the signed upload address', 'PUT /resolve'], $steps);

        $evidenceSent = $this->sentTo($path.'/evidence', 'POST')[0];
        $this->assertSame('08031234567', $evidenceSent['customer_phone']);
        $this->assertSame('Ada Okafor', $evidenceSent['customer_name']);

        $this->assertSame('evidence-pack-'.$order->reference.'.pdf', $this->sentTo($path.'/upload_url')[0]['upload_filename']);
        $this->assertStringStartsWith('%PDF-', $this->sentTo('s3.eu-west-1.amazonaws.com', 'PUT')[0]->body());

        $resolve = $this->sentTo($path.'/resolve', 'PUT')[0];
        $this->assertSame('declined', $resolve['resolution']);
        $this->assertSame(0, $resolve['refund_amount']);
        $this->assertSame('qesp8a4df1xejihd9x5q.pdf', $resolve['uploaded_filename']);
        $this->assertSame(21, $resolve['evidence']);
        $this->assertStringContainsString('attached document', $resolve['message']);

        $dispute->refresh();
        $this->assertSame(Dispute::SUBMITTED, $dispute->response);
        $this->assertSame('resolved', $dispute->processor_status);
        $this->assertSame('21', $dispute->evidence->uploads['paystack_evidence']['handle']);
        $this->assertSame('21', $dispute->evidence->sent['processor_evidence']);
        $this->assertSame('21', AuditLog::query()->where('action', 'dispute.evidence_submitted')->sole()->metadata['processor_evidence']);

        // Pressed again: nothing.
        $before = count($this->sentToProcessor);
        app(DisputeDesk::class)->submit($dispute, $finance);
        $this->assertCount($before, $this->sentToProcessor);
    }

    public function test_words_corrected_after_paystack_took_the_evidence_go_as_new_evidence_and_the_answer_names_it(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [, $dispute] = $this->disputeOnPaystack();
        $path = 'api.paystack.co/dispute/'.$dispute->gateway_reference;

        // Paystack takes the evidence; its file store does not take the file.
        $this->processor['s3.eu-west-1.amazonaws.com/*'] = fn () => Http::response('', 503);
        $this->expectRefusal(fn () => app(DisputeDesk::class)->submit($dispute->fresh(), $admin), 'would not take');

        // Tried again with the same words: the evidence Paystack has is them,
        // and is not given again.
        $this->expectRefusal(fn () => app(DisputeDesk::class)->submit($dispute->fresh(), $admin), 'would not take');
        $this->assertCount(1, $this->sentTo($path.'/evidence', 'POST'));

        // Corrected, then sent: the corrected words go as evidence of their
        // own, and the answer names that one, not the first.
        app(DisputeDesk::class)->saveDraft($dispute->fresh(), ['service_details' => 'CORRECTED WORDS'], $admin);
        $this->processor['s3.eu-west-1.amazonaws.com/*'] = fn () => Http::response('', 200);
        app(DisputeDesk::class)->submit($dispute->fresh(), $admin);

        $given = $this->sentTo($path.'/evidence', 'POST');
        $this->assertCount(2, $given);
        $this->assertSame('CORRECTED WORDS', $given[1]['service_details']);
        $this->assertSame(22, $this->sentTo($path.'/resolve', 'PUT')[0]['evidence']);

        // What is on record as sent is what Paystack was given.
        $evidence = $dispute->fresh()->evidence;
        $this->assertSame('CORRECTED WORDS', $evidence->sent['fields']['service_details']);
        $this->assertSame('22', $evidence->sent['processor_evidence']);
        $this->assertSame('22', AuditLog::query()->where('action', 'dispute.evidence_submitted')->sole()->metadata['processor_evidence']);
    }

    public function test_paystack_will_not_be_sent_evidence_without_a_phone_number(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [, $dispute] = $this->disputeOnPaystack(['customer' => ['phone' => null]]);

        $this->assertSame('', $dispute->evidence->fields['customer_phone']);
        $this->expectRefusal(fn () => app(DisputeDesk::class)->submit($dispute, $admin), 'phone number');
        $this->assertCount(0, $this->sentTo('/evidence'));

        app(DisputeDesk::class)->saveDraft($dispute, ['customer_phone' => '+234 803 123 4567'], $admin);
        app(DisputeDesk::class)->submit($dispute->fresh(), $admin);

        $this->assertSame('+234 803 123 4567', $this->sentTo('/evidence', 'POST')[0]['customer_phone']);
    }

    public function test_accepting_a_paystack_dispute_sends_the_receipt_and_the_whole_amount_and_its_resolution_is_the_loss(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, $dispute] = $this->disputeOnPaystack();

        app(DisputeDesk::class)->accept($dispute, $admin, 'Nobody could show the tickets were used.');

        $path = 'api.paystack.co/dispute/'.$dispute->gateway_reference;
        $this->assertSame('receipt-'.$order->reference.'.pdf', $this->sentTo($path.'/upload_url')[0]['upload_filename']);

        $resolve = $this->sentTo($path.'/resolve', 'PUT')[0];
        $this->assertSame('merchant-accepted', $resolve['resolution']);
        $this->assertSame($order->total_amount, $resolve['refund_amount']);
        $this->assertSame('Nobody could show the tickets were used.', $resolve['message']);
        $this->assertSame('qesp8a4df1xejihd9x5q.pdf', $resolve['uploaded_filename']);
        $this->assertCount(0, $this->sentTo($path.'/evidence'));

        $this->assertSame(Dispute::ACCEPTED, $dispute->fresh()->response);

        // The receipt left for Paystack, so the acceptance lists it the way a
        // send lists its documents: name, checksum and Paystack's name for it.
        $accepted = AuditLog::query()->where('action', 'dispute.accepted')->sole();
        $receipt = collect($accepted->metadata['files'])->sole();
        $this->assertSame('receipt', $receipt['kind']);
        $this->assertSame('receipt-'.$order->reference.'.pdf', $receipt['name']);
        $this->assertSame(64, strlen($receipt['sha256']));
        $this->assertSame('qesp8a4df1xejihd9x5q.pdf', $receipt['processor_file']);

        // Paystack's resolution arrives, and is the loss.
        $this->paystackDisputeNotice(array_replace($this->paystackDisputes[$dispute->gateway_reference], [
            'status' => 'resolved',
            'resolution' => 'merchant-accepted',
            'resolvedAt' => now()->toIso8601ZuluString(),
        ]), 'charge.dispute.resolve')->assertOk();

        $dispute->refresh();
        $this->assertSame('lost', $dispute->status);
        $this->assertSame('merchant-accepted', $dispute->processor_status);
        $this->assertSame(1, LedgerEntry::query()->where('order_id', $order->id)->where('type', 'chargeback')->count());
    }

    public function test_an_acceptance_paystack_refused_is_audited_with_the_receipt_that_had_already_left(): void
    {
        $admin = $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        [$order, $dispute] = $this->disputeOnPaystack();
        $path = 'api.paystack.co/dispute/'.$dispute->gateway_reference;

        $answers = $this->processor['api.paystack.co/dispute/*'];
        $this->processor['api.paystack.co/dispute/*'] = fn (ClientRequest $request) => str_ends_with(explode('?', $request->url())[0], '/resolve')
            ? Http::response(['status' => false, 'message' => 'Dispute is not awaiting merchant feedback'], 400)
            : $answers($request);

        $this->expectRefusal(fn () => app(DisputeDesk::class)->accept($dispute, $admin, 'The buyer is right.'), 'Nothing is marked as accepted');

        $refused = AuditLog::query()->where('action', 'dispute.accept_not_accepted')->sole();
        $this->assertSame($admin->id, $refused->actor_id);
        $this->assertStringContainsString('not awaiting merchant feedback', $refused->metadata['error']);
        $this->assertSame('receipt-'.$order->reference.'.pdf', collect($refused->metadata['files'])->sole()['name']);
        $this->assertStringNotContainsString('ada@example.com', (string) json_encode($refused->metadata));
        $this->assertNull($dispute->fresh()->response);

        // Tried again once Paystack will: the receipt it already has is named,
        // not uploaded a second time.
        $this->processor['api.paystack.co/dispute/*'] = $answers;
        app(DisputeDesk::class)->accept($dispute->fresh(), $admin, 'The buyer is right.');

        $this->assertCount(1, $this->sentTo($path.'/upload_url'));
        $this->assertSame(Dispute::ACCEPTED, $dispute->fresh()->response);
    }

    // --- helpers ------------------------------------------------------------------

    /** @return array{0: Order, 1: Dispute} */
    private function disputeOnPaystack(array $overrides = []): array
    {
        [$event, $type] = $this->night('NGN');
        $order = $this->buy($event, $type, ['REMOTE_ADDR' => self::BUYER_ADDRESS], ['User-Agent' => self::BROWSER]);
        $transaction = random_int(100000000, 999999999);

        $this->paystackPaid($order, $transaction)->assertOk();
        $this->paystackAnswersDisputes();

        $this->paystackDisputeNotice($this->paystackDispute($order->refresh(), $transaction, $overrides))->assertOk();

        return [$order, Dispute::query()->where('gateway', 'paystack')->latest('created_at')->firstOrFail()];
    }

    private function expectRefusal(callable $attempt, string $saying): void
    {
        try {
            $attempt();
            $this->fail('It was not refused.');
        } catch (StaffActionRefused $refused) {
            $this->assertStringContainsString($saying, $refused->getMessage());
        }
    }
}
