<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invites+ — digital invitations and RSVP, as a module rather than a product.
 *
 * A wedding and a club night are the same noun. Both have an organization, a
 * date, a venue, a timezone, a shareable page, people arriving, and someone on
 * the door checking them in. Building Invites+ separately would mean a second
 * events table, a second guest concept, a second scanner, and a second admin —
 * and then keeping all of it in step.
 *
 * So events gain a kind, and this migration adds only what genuinely does not
 * exist yet: an invited guest who has not bought anything, the questions asked
 * of them, and their answers.
 *
 * The reuse that matters most: an accepted RSVP issues an ordinary ticket. The
 * door scanner, the scan log, and the duplicate-entry protection then work at a
 * wedding without a line of new code.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A ticketed event sells entry. An invitation event gives it, to a
        // known list. Almost everything else about them is identical.
        Schema::table('events', function (Blueprint $table) {
            $table->string('kind')->default('ticketed')->after('title');
            $table->index(['kind', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_kind_check
            CHECK (kind IN ('ticketed', 'invitation'))
        SQL);

        /*
         * Someone invited, who may never respond.
         *
         * Deliberately not a user. Wedding guests are added from a spreadsheet
         * by someone else, and most will never hold an account — the previous
         * platform's RSVP feature wrote straight into the tickets table with
         * magic values for exactly this reason, and it made both concepts
         * harder to reason about.
         */
        Schema::create('guests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // "Smith family", "Work", "Bride's side" — how hosts actually think
            // about a guest list, and what they filter and message by.
            $table->string('group_name')->nullable();

            // How many people this invitation admits in total, including the
            // named guest. A plus-one is max_party_size = 2.
            $table->unsignedSmallInteger('max_party_size')->default(1);

            // The guest's own link. Long and random: it authenticates them, so
            // it must not be guessable from a name or an email address.
            $table->string('invite_token', 64)->unique();

            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('last_reminded_at')->nullable();
            $table->timestampTz('opened_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['event_id', 'group_name']);
            // A guest list is imported repeatedly from an edited spreadsheet,
            // so the same address must not become three invitations.
            $table->unique(['event_id', 'email']);
        });

        DB::statement('ALTER TABLE guests ADD CONSTRAINT guests_party_size_check CHECK (max_party_size >= 1)');

        /*
         * The answer.
         *
         * Separate from the guest so a change of mind is a new row rather than
         * an overwrite — hosts cater from these numbers, and "they said yes
         * last week" needs to survive them saying no today.
         */
        Schema::create('rsvps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('status');           // attending | declined | tentative
            $table->unsignedSmallInteger('party_size')->default(1);
            $table->text('message')->nullable(); // a note to the host

            // Superseded when the guest answers again. Exactly one live row per
            // guest, enforced by a partial index below.
            $table->timestampTz('superseded_at')->nullable();

            $table->timestampTz('responded_at');
            $table->timestampsTz();

            $table->index(['event_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE rsvps ADD CONSTRAINT rsvps_status_check
            CHECK (status IN ('attending', 'declined', 'tentative'))
        SQL);
        DB::statement('ALTER TABLE rsvps ADD CONSTRAINT rsvps_party_size_check CHECK (party_size >= 0)');

        // One current answer per guest. Without this, a double submission
        // leaves the host with two live counts and no way to tell which is real.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX rsvps_one_live_per_guest
            ON rsvps (guest_id) WHERE superseded_at IS NULL
        SQL);

        /*
         * What the host asks.
         *
         * Dietary requirements, song requests, which day they are coming. Free
         * enough to be useful, structured enough to report on — a text blob
         * would make "how many vegetarians" unanswerable.
         */
        Schema::create('rsvp_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->string('type');             // text | choice | multi_choice | boolean
            $table->jsonb('options')->nullable(); // for choice types
            $table->boolean('required')->default(false);

            // Asked once per invitation, or once per person in the party.
            $table->boolean('per_attendee')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['event_id', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE rsvp_questions ADD CONSTRAINT rsvp_questions_type_check
            CHECK (type IN ('text', 'choice', 'multi_choice', 'boolean'))
        SQL);

        Schema::create('rsvp_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rsvp_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rsvp_question_id')->constrained()->cascadeOnDelete();

            // Which member of the party, for per-attendee questions. Null means
            // the answer is for the invitation as a whole.
            $table->unsignedSmallInteger('attendee_index')->nullable();

            // jsonb rather than text, so a multi-choice answer stays a list and
            // stays queryable.
            $table->jsonb('value');

            $table->timestampsTz();
            $table->index('rsvp_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvp_answers');
        Schema::dropIfExists('rsvp_questions');
        Schema::dropIfExists('rsvps');
        Schema::dropIfExists('guests');

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_kind_check');

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn('kind');
        });
    }
};
