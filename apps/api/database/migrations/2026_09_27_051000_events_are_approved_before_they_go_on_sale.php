<?php

use App\Models\Event;
use App\Services\Events\EventSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Nothing goes on sale until somebody at myFiesta has looked at it.
 *
 * An organizer sends a draft for review and it waits, frozen, in `in_review`
 * until staff approve it (on sale) or send it back with a reason (a draft
 * again). Four things are kept for that:
 *
 * - on the event, when the current review began (the queue is oldest first,
 *   and says how long each has waited), and the last approval: when, by whom,
 *   and a fingerprint of everything a buyer sees as it stood. An event taken
 *   off sale and put back unchanged goes straight back on sale; one whose
 *   fingerprint no longer matches goes back through review;
 * - event_reviews, the history: each submission, approval, rejection and
 *   withdrawal, with the reviewer's reason and, for submissions and
 *   approvals, the content as it stood — which is what "what changed since it
 *   was approved" is worked out from;
 * - on a picture, the file it was first uploaded as. A copy made for another
 *   night (EventDuplicator) is new files with the same picture in them, and
 *   the next date of an approved series has to be recognisable as the same
 *   poster;
 * - the status constraint, which gains `in_review` and ties it to its start.
 *
 * Every event on sale when this ships is marked approved as it stands. It was
 * put on sale under the rules of the time, and taking the whole catalogue off
 * sale to be looked at again would punish every organizer for a rule that did
 * not exist when they published.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_status_check
            CHECK (status IN ('draft', 'in_review', 'published', 'cancelled'))
        SQL);

        Schema::table('events', function (Blueprint $table) {
            // When the review now waiting began. Only while in review.
            $table->timestampTz('submitted_at')->nullable();

            // The last approval that stands.
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('approved_fingerprint', 64)->nullable();

            $table->index(['status', 'submitted_at']);
        });

        // In review exactly when a review has begun: the queue orders by it,
        // and a row that disagrees would wait for ever or never.
        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_submitted_at_check
            CHECK ((status = 'in_review') = (submitted_at IS NOT NULL))
        SQL);

        Schema::create('event_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('action', 16);

            // How an approval came about. A decision on the review queue, or
            // one of the staff actions and rules that count as one.
            $table->string('via', 24)->nullable();

            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Kept beside the key so the history reads the same after the
            // account is erased.
            $table->string('actor_label')->nullable();

            // The reviewer's words, sent to the organizer as written.
            $table->text('reason')->nullable();

            $table->char('fingerprint', 64)->nullable();
            $table->jsonb('snapshot')->nullable();

            $table->timestampTz('created_at');
        });

        // The order the steps happened in. Two steps inside the same second —
        // sent, then taken straight back — are still told apart, which a
        // timestamp to the second and a random id cannot promise.
        DB::statement('ALTER TABLE event_reviews ADD COLUMN seq bigint GENERATED ALWAYS AS IDENTITY');
        DB::statement('CREATE INDEX event_reviews_event_id_seq_index ON event_reviews (event_id, seq)');

        DB::statement(<<<'SQL'
            ALTER TABLE event_reviews ADD CONSTRAINT event_reviews_action_check
            CHECK (action IN ('submitted', 'approved', 'rejected', 'withdrawn'))
        SQL);

        // A rejection with no reason is one the organizer cannot act on.
        DB::statement(<<<'SQL'
            ALTER TABLE event_reviews ADD CONSTRAINT event_reviews_reason_check
            CHECK (action <> 'rejected' OR (reason IS NOT NULL AND length(trim(reason)) > 0))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE event_reviews ADD CONSTRAINT event_reviews_via_check
            CHECK (
                (action = 'approved' AND via IN ('review', 'takedown_lifted', 'suspension_lifted', 'series', 'existing', 'imported'))
                OR (action <> 'approved' AND via IS NULL)
            )
        SQL);

        Schema::table('event_images', function (Blueprint $table) {
            $table->string('original_path')->nullable();
        });

        $this->approveWhatIsOnSale();
    }

    /**
     * Every event on sale now, approved as it stands.
     *
     * Through the same snapshot the review uses, so the fingerprint is the one
     * a later "put back on sale" is compared against.
     */
    private function approveWhatIsOnSale(): void
    {
        Event::query()
            ->where('status', 'published')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $snapshot = EventSnapshot::of($event);
                    $fingerprint = EventSnapshot::fingerprint($snapshot);
                    $at = $event->published_at ?? now();

                    DB::table('events')->where('id', $event->id)->update([
                        'approved_at' => $at,
                        'approved_fingerprint' => $fingerprint,
                    ]);

                    DB::table('event_reviews')->insert([
                        'id' => (string) Str::uuid(),
                        'event_id' => $event->id,
                        'action' => 'approved',
                        'via' => 'existing',
                        'fingerprint' => $fingerprint,
                        'snapshot' => json_encode($snapshot),
                        'created_at' => $at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Back to a draft rather than lost: the organizer publishes it again
        // under the old rules. Its review ends with it, in the same update —
        // the constraint tying the two together is still in place here.
        DB::table('events')->where('status', 'in_review')->update(['status' => 'draft', 'submitted_at' => null]);

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_submitted_at_check');

        Schema::table('event_images', function (Blueprint $table) {
            $table->dropColumn('original_path');
        });

        Schema::dropIfExists('event_reviews');

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['submitted_at', 'approved_at', 'approved_fingerprint']);
        });

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_status_check
            CHECK (status IN ('draft', 'published', 'cancelled'))
        SQL);
    }
};
