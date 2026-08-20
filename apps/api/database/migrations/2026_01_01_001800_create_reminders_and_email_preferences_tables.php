<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reminding people they bought a ticket, and letting them stop.
 *
 * The reminding is the easy half. The half that matters legally is the second
 * table: CASL in Canada and the NDPA in Nigeria both require a working way out,
 * and CASL specifically requires it to survive ten days and take no more than
 * two clicks. Building the sending first and the unsubscribe later is how a
 * platform ends up with an installed base it cannot legally email.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per reminder an event will send.
         *
         * Offsets are stored, and send_at is derived from the event's start.
         * Storing only an absolute time would leave every reminder pointing at
         * the old moment when an organizer moves the event — which is exactly
         * when a reminder matters most and exactly when it would be wrong.
         */
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // Minutes before the event starts. Minutes rather than hours
            // because "thirty minutes before doors" is a thing an organizer
            // will eventually want and hours cannot express.
            $table->unsignedInteger('offset_minutes');

            $table->string('status')->default('scheduled');
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('recipients')->nullable();

            $table->timestampsTz();

            // One reminder per offset per event: two identical reminders is
            // never a thing anyone meant.
            $table->unique(['event_id', 'offset_minutes']);
            $table->index(['status', 'event_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE event_reminders ADD CONSTRAINT event_reminders_status_check
            CHECK (status IN ('scheduled', 'sending', 'sent', 'cancelled'))
        SQL);

        // A reminder after the event has started is a message about a party
        // somebody has already missed.
        DB::statement(<<<'SQL'
            ALTER TABLE event_reminders ADD CONSTRAINT event_reminders_offset_check
            CHECK (offset_minutes > 0 AND offset_minutes <= 131400)
        SQL);

        /*
         * Who has already had which reminder.
         *
         * The reason this exists rather than a single sent flag on the reminder:
         * sending to four hundred people is four hundred queued messages, and a
         * process that dies after two hundred has to resume rather than start
         * again. Without a row per recipient, the retry sends the first two
         * hundred people a second copy of the same email.
         */
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->foreignUuid('reminder_id')->constrained('event_reminders')->cascadeOnDelete();
            $table->string('email');
            $table->timestampTz('created_at');

            // The pair is the identity, and the uniqueness is the guarantee.
            $table->primary(['reminder_id', 'email']);
        });

        /*
         * What an address has asked not to receive.
         *
         * Keyed on the address, not on a user. Guest checkout is the primary
         * path, so most people who need to unsubscribe have no account — and a
         * preference that only works for account holders is not a preference.
         */
        Schema::create('email_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();

            // Separate, because they are different promises. Reminders are
            // about something you bought; marketing is about something you
            // have not. Somebody who wants their ticket reminder and no
            // newsletters must be able to say exactly that.
            $table->timestampTz('reminders_opted_out_at')->nullable();
            $table->timestampTz('marketing_opted_out_at')->nullable();

            /*
             * What the one-click link carries.
             *
             * Random and per-address rather than a signed email address in the
             * URL: an unsubscribe link ends up in forwarded mail, browser
             * history and corporate scanners, and a token that identifies
             * nothing on its own is a smaller thing to leak. It also means the
             * link keeps working when the signing key is rotated.
             */
            $table->string('token', 64)->unique();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
        Schema::dropIfExists('event_reminders');
        Schema::dropIfExists('email_preferences');
    }
};
