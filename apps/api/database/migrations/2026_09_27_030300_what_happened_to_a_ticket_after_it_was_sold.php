<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to an order's tickets between the sale and the door.
 *
 * "I never got my tickets" and "I don't recognise this" are the two things a
 * buyer tells their bank after a night they went to, and both are answered by
 * what happened in between: the tickets were issued at 21:04, emailed to the
 * address they gave at 21:04 and accepted by the mail provider, the link in
 * that email was opened three times, and the app showed the QR at the door.
 * Written as each thing happens, from our own server, so a dispute months
 * later is answered from records rather than reconstructed from memory.
 *
 * What is not here, on purpose: the door itself. Every scan is already in
 * ticket_scans with who scanned it and when, and a copy here would be a second
 * account of the same thing that could disagree with the first. Nor are
 * transfers copied — a row here points at the ticket_transfers row.
 *
 * An internet address and a browser name are kept on the rows that are
 * somebody opening something (a link, the QR in the app, a calendar file),
 * and on no other row — nor on a ticket shown in the app of somebody it was
 * passed on to, who is not the buyer. Nothing is worked out from them.
 *
 * Append-only, enforced by the database like the audit trail: no row is ever
 * changed. Rows are deleted 18 months after the event, by
 * disputes:prune-evidence and nothing else — the trigger lets a delete
 * through only inside that prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained();
            // At least one of the two. An order-wide thing (its tickets
            // issued, its link opened) names the order; one ticket's thing
            // names the ticket, and its order when it has one — a ticket
            // given away by an organizer has none.
            $table->foreignUuid('order_id')->nullable()->constrained();
            $table->foreignUuid('ticket_id')->nullable()->constrained();
            $table->foreignUuid('ticket_transfer_id')->nullable()->constrained();

            $table->string('kind', 32);

            // For an email: which one, to whom, and the id the mail
            // provider gave it — which is what their own logs are searched by.
            $table->string('mailable', 120)->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('message_id')->nullable();

            // For somebody opening something: where from, and on what.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Anything else the row needs to say: which tickets, a subject.
            // Never a ticket code.
            $table->jsonb('details')->nullable();

            // Written once, so there is no updated_at.
            $table->timestampTz('occurred_at');

            $table->index(['order_id', 'kind', 'occurred_at']);
            $table->index(['ticket_id', 'kind', 'occurred_at']);
            $table->index(['event_id', 'occurred_at']);
        });

        DB::statement("
            ALTER TABLE ticket_activity ADD CONSTRAINT ticket_activity_kind_check
            CHECK (kind IN (
                'tickets_issued', 'email_sent', 'ticket_page_opened',
                'order_link_opened', 'qr_shown_in_app', 'calendar_downloaded', 'ticket_transferred'
            ))
        ");

        DB::statement('
            ALTER TABLE ticket_activity ADD CONSTRAINT ticket_activity_names_what_it_is_about
            CHECK (order_id IS NOT NULL OR ticket_id IS NOT NULL)
        ');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ticket_activity_is_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('myfiesta.retention_prune', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'ticket_activity is deleted only by its retention prune';
                    END IF;

                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'ticket_activity is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ticket_activity_no_update_or_delete
            BEFORE UPDATE OR DELETE ON ticket_activity
            FOR EACH ROW EXECUTE FUNCTION ticket_activity_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_activity_no_update_or_delete ON ticket_activity');
        DB::unprepared('DROP FUNCTION IF EXISTS ticket_activity_is_append_only()');

        Schema::dropIfExists('ticket_activity');
    }
};
