<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An organizer writing to the people holding tickets to their event.
 *
 * The venue changed, the doors open an hour earlier, the support act dropped
 * out. The previous platform had a screen for this and the rebuild had none, so
 * an organizer with news had no way to reach their own audience except by
 * exporting a guest list and using their personal mail client — which is how a
 * ticket buyer's address ends up in somebody's Gmail contacts forever.
 *
 * Stored rather than fired and forgotten. An organizer needs to see what they
 * already sent before sending again, and "did the venue change email go out?"
 * has to have an answer that is not somebody's memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject', 160);
            $table->text('body');

            /*
             * Whether this overrides somebody's opt-out.
             *
             * Ordinary news respects the same preference reminders do — if
             * somebody said stop emailing me about events I hold tickets to,
             * a lineup announcement is exactly what they meant.
             *
             * Important is for the messages where not knowing costs somebody a
             * wasted journey: a venue change, a cancellation, a new start time.
             * Those reach everyone, and the email says why it arrived despite
             * the opt-out. Making this a choice rather than a global setting is
             * deliberate — an organizer who marks everything important will be
             * visible in this column.
             */
            $table->boolean('important')->default(false);

            $table->string('status')->default('queued');
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('recipients')->nullable();
            $table->unsignedInteger('suppressed')->nullable();

            $table->timestampsTz();

            $table->index(['event_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE event_messages ADD CONSTRAINT event_messages_status_check
            CHECK (status IN ('queued', 'sending', 'sent', 'failed'))
        SQL);

        /*
         * Who has already had which message.
         *
         * The same guarantee the reminder deliveries table gives: sending to
         * four hundred people is four hundred queued messages, and a worker
         * that dies at two hundred has to resume rather than start again.
         */
        Schema::create('event_message_deliveries', function (Blueprint $table) {
            $table->foreignUuid('message_id')->constrained('event_messages')->cascadeOnDelete();
            $table->string('email');
            $table->timestampTz('created_at');

            $table->primary(['message_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_message_deliveries');
        Schema::dropIfExists('event_messages');
    }
};
