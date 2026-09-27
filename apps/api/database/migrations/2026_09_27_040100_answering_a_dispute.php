<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answering a chargeback, rather than only knowing there was one.
 *
 * Until now a dispute was a row with a deadline, and the answer was put
 * together by hand in the processor's dashboard — if anybody noticed in time.
 * So the platform now puts the answer together itself when a dispute opens,
 * from the records kept for that purpose (payment_evidence, ticket_activity,
 * the door's scans, event_completions, the refund policy as it was shown),
 * and staff read it, correct it and send it, or decide the buyer is right and
 * accept.
 *
 * On the dispute itself, nothing personal: what the processor calls its state
 * and the card network's reason code, whether and when it was answered and by
 * whom, and when staff were told and reminded — each reminder once.
 *
 * The answer lives beside it (dispute_evidence): the processor's own account
 * of the dispute, the fields that will be sent, the checklist of what was found
 * and what was not, and what was actually sent. It names the buyer, so it goes
 * with the rest of the evidence 18 months after the night, once the dispute
 * has closed (disputes:prune-evidence). The dispute row stays: it is a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            // The processor's own word for where the dispute is (Stripe's
            // needs_response or under_review, Paystack's
            // awaiting-merchant-feedback) and, when it closes, how.
            $table->string('processor_status', 40)->nullable();
            // The card network's code for the reason, when the processor
            // passes it on: 10.4 is Visa's fraud, 4853 Mastercard's "not as
            // described".
            $table->string('network_reason_code', 32)->nullable();
            $table->timestampTz('processor_checked_at')->nullable();

            // Our answer: the evidence sent, or the dispute conceded. Once
            // either is done it cannot be done again.
            $table->string('response', 16)->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->foreignUuid('responded_by')->nullable()->constrained('users')->nullOnDelete();

            // Admin and Finance told when it opened, and reminded as the
            // deadline nears — each once, however often the sweep runs.
            $table->timestampTz('staff_told_at')->nullable();
            $table->timestampTz('reminded_five_days_at')->nullable();
            $table->timestampTz('reminded_two_days_at')->nullable();

            $table->index(['status', 'evidence_due_at']);
        });

        DB::statement("
            ALTER TABLE disputes ADD CONSTRAINT disputes_response_check
            CHECK (response IS NULL OR response IN ('submitted', 'accepted'))
        ");

        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispute_id')->unique()->constrained()->cascadeOnDelete();
            // Which night, so the prune finds what is past its window.
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            // The buyer's address as the order had it when this was put
            // together: what a privacy request finds it by.
            $table->string('customer_email')->nullable();

            // Which reason's evidence this is (fraud, not_received, refund,
            // duplicate, general).
            $table->string('kind', 16);

            // The processor's account of the dispute, reduced: its reason,
            // deadline, what it says could strengthen the answer, and — for
            // Paystack — what the buyer told it. Never the card's first six.
            $table->jsonb('processor')->nullable();

            // The words that will be sent, field by field, as staff last left
            // them; and the files that go with them. json rather than jsonb
            // for the words, because jsonb keeps an object's keys in an order
            // of its own and the page shows the fields in the order they were
            // written: what was sold first, the summary last.
            $table->json('fields');
            $table->jsonb('files');
            // What was found and what was not, and anything that suggests the
            // buyer may be right.
            $table->jsonb('checklist');
            $table->jsonb('cautions')->nullable();
            // Visa's Compelling Evidence 3.0: whether our records establish it,
            // and the earlier payments that do.
            $table->jsonb('compelling_evidence')->nullable();

            // Files given to the processor, so a second try sends none twice.
            $table->jsonb('uploads')->nullable();
            // What was sent, when it was, in the order it was written.
            $table->json('sent')->nullable();

            $table->timestampTz('built_at');
            $table->timestampTz('edited_at')->nullable();
            $table->foreignUuid('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidence');

        DB::statement('ALTER TABLE disputes DROP CONSTRAINT IF EXISTS disputes_response_check');

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropIndex(['status', 'evidence_due_at']);
            $table->dropConstrainedForeignId('responded_by');
            $table->dropColumn([
                'processor_status', 'network_reason_code', 'processor_checked_at',
                'response', 'responded_at', 'staff_told_at',
                'reminded_five_days_at', 'reminded_two_days_at',
            ]);
        });
    }
};
