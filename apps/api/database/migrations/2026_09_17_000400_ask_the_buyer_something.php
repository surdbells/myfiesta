<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Questions at checkout.
 *
 * An organizer needs to ask things a basket cannot say: the name on each
 * ticket when a door checks ID, a phone number for a table booking, dietary
 * needs for a dinner, "how did you hear about this" for a promoter. Every
 * platform they would otherwise be using has this, usually called an order
 * form, and it is the feature they ask for by name.
 *
 * The model to ask it with already existed and was wired to the wrong half of
 * the product. `rsvp_questions` — a label, a type, options, required, asked
 * once or asked per person — is exactly the right shape, and it could only be
 * answered by a wedding guest replying to an invitation.
 *
 * So the table is renamed to what it always was, and answers from a ticketed
 * order get somewhere to live. Nothing about the invitation flow changes
 * except the name of a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The same question, asked by both halves of the product.
         *
         * Renamed rather than copied: two question tables would mean two
         * editors, two validators and two exports, and they would drift the
         * first time somebody added a type to one of them.
         */
        Schema::rename('rsvp_questions', 'event_questions');

        DB::statement('ALTER TABLE event_questions RENAME CONSTRAINT rsvp_questions_type_check TO event_questions_type_check');

        Schema::table('event_questions', function (Blueprint $table) {
            /*
             * Removing a question must not remove what people answered.
             *
             * An organizer tidying their form the week after the night would
             * otherwise delete the dietary requirements they collected it for.
             * Deleting hides the question from the next buyer; the answers,
             * and the label that makes them mean anything, stay.
             */
            $table->softDeletesTz();
        });

        Schema::table('rsvp_answers', function (Blueprint $table) {
            $table->renameColumn('rsvp_question_id', 'event_question_id');
        });

        /*
         * What a buyer answered.
         *
         * Two shapes in one table, which the check constraint below keeps
         * honest. A question asked once per order — how did you hear about
         * this — has no line and no index. A question asked of each person has
         * both: which line they are on, and which of that line's tickets they
         * are.
         *
         * Answers are written when the order is created, which is before any
         * ticket exists: a buyer answers on the way to the payment page, and
         * the tickets are minted by the webhook that follows. `ticket_id` is
         * stamped on at that point, so a door scanning a code can read what
         * the person in front of them answered without working backwards
         * through an order.
         */
        Schema::create('order_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_question_id')->constrained('event_questions')->cascadeOnDelete();

            $table->foreignUuid('order_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attendee_index')->nullable();

            // Filled in at issue, null until then — and null for ever on a
            // question asked once for the whole order.
            $table->foreignUuid('ticket_id')->nullable()->constrained()->nullOnDelete();

            // jsonb and always a list, matching rsvp_answers: a multi-choice
            // answer stays a list and stays queryable, and "how many asked for
            // the vegetarian option" is a question with an answer.
            $table->jsonb('value');

            $table->timestampsTz();

            $table->index('order_id');
            $table->index('ticket_id');
            $table->index('event_question_id');
        });

        // Either it belongs to the order or it belongs to one person on it.
        // Half of the pair set is a row nobody can attribute.
        DB::statement(<<<'SQL'
            ALTER TABLE order_answers ADD CONSTRAINT order_answers_scope_check
            CHECK (
                (order_line_id IS NULL AND attendee_index IS NULL)
                OR (order_line_id IS NOT NULL AND attendee_index IS NOT NULL)
            )
        SQL);

        // One answer per question, per thing being asked. A retried submission
        // must not double the count an organizer caters from.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_answers_one_per_order
            ON order_answers (order_id, event_question_id) WHERE order_line_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX order_answers_one_per_attendee
            ON order_answers (order_line_id, attendee_index, event_question_id)
            WHERE order_line_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_answers');

        Schema::table('rsvp_answers', function (Blueprint $table) {
            $table->renameColumn('event_question_id', 'rsvp_question_id');
        });

        Schema::table('event_questions', function (Blueprint $table) {
            $table->dropSoftDeletesTz();
        });

        DB::statement('ALTER TABLE event_questions RENAME CONSTRAINT event_questions_type_check TO rsvp_questions_type_check');

        Schema::rename('event_questions', 'rsvp_questions');
    }
};
