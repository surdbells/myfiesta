<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket sent on knows whose it became, and how it was sent.
 *
 * `to_user_id` is who the ticket went to, by account rather than by the
 * address typed. The link a ticket is sent with opens it while it is still
 * that account's (TicketLink), and the address would not do: erasing the
 * person who sent it clears the addresses on what they sent, and the person
 * it went to would lose their ticket page for somebody else's request. It is
 * also how the recipient's own erasure finds the rows that name them
 * (config/personal_data.php), which keying on the sender never did.
 *
 * `via` is how it was asked for: from the phone app, from a ticket link, or
 * by support. A send from a link is made by whoever holds the link, with no
 * account to name, and the admin panel showed it as made by nobody.
 *
 * Both nullable: transfers made before this were by the phone or by support,
 * and say who in `initiated_by`. Their recipients are matched up by address
 * where an account has it, so an erasure reaches them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_transfers', function (Blueprint $table) {
            $table->foreignUuid('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('via', 16)->nullable();

            $table->index('to_user_id');
        });

        DB::statement(<<<'SQL'
            UPDATE ticket_transfers
            SET to_user_id = users.id
            FROM users
            WHERE ticket_transfers.to_user_id IS NULL
              AND lower(users.email) = lower(ticket_transfers.to_email)
        SQL);
    }

    public function down(): void
    {
        Schema::table('ticket_transfers', function (Blueprint $table) {
            $table->dropIndex(['to_user_id']);
            $table->dropConstrainedForeignId('to_user_id');
            $table->dropColumn('via');
        });
    }
};
