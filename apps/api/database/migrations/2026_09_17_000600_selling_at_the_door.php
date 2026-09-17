<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-ups.
 *
 * A large share of a club night pays at the door, and until now the only way
 * to put one of those people through was to comp them: a free ticket, no money
 * anywhere, and a guest list that grows every hour for no recorded reason. The
 * cash went in a tin and the platform never heard about it.
 *
 * A door sale is an ordinary order. Same stock, same tickets, same scan log,
 * same reports — with three differences, each of which is a column here.
 *
 * It says how it was paid for, because somebody counts a tin at 3am and the
 * two numbers have to agree. It says who took it, because that is the only
 * control there is over money handled by a person standing in a doorway. And
 * it has no buyer email, because the person in front of you paying cash is not
 * going to spell one out — they are scanned in on the spot and walk away with
 * nothing to lose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Where the sale happened. Everything before this migration was
            // online, which is what the default records.
            $table->string('channel')->default('online')->after('status');

            // How the money arrived. Null online, where the gateway column
            // already says it; required at a door, where nothing else does.
            // Transfer is here because in Lagos it is the common one.
            $table->string('payment_method')->nullable()->after('channel');

            // Who took it, and on which phone. A pass token belongs to the
            // member who issued it, so the pass is what names the till.
            $table->foreignUuid('sold_by_user_id')->nullable()->after('payment_method')
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('door_pass_id')->nullable()->after('sold_by_user_id')
                ->constrained()->nullOnDelete();

            $table->index(['event_id', 'channel']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_channel_check
            CHECK (channel IN ('online', 'door'))
        SQL);

        // Said both ways round: an online order has no payment method because
        // the gateway column carries that, and a door sale has nothing else to
        // say how the money arrived.
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check
            CHECK (
                (channel = 'door') = (payment_method IS NOT NULL)
                AND (payment_method IS NULL OR payment_method IN ('cash', 'card', 'transfer'))
            )
        SQL);

        // --- somebody who never gave an address ------------------------------

        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_email')->nullable()->change();
        });

        // Online, it is still required — it is the tickets, and the only way
        // back to them. Only a door sale may be anonymous.
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_buyer_email_check
            CHECK (channel = 'door' OR buyer_email IS NOT NULL)
        SQL);

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('owner_email')->nullable()->change();
        });

        // A ticket with nobody's address has to have an order behind it. That
        // is what keeps a nameless ticket from being something anybody can
        // mint out of nowhere: it exists because a sale was recorded.
        DB::statement(<<<'SQL'
            ALTER TABLE tickets ADD CONSTRAINT tickets_owner_email_check
            CHECK (owner_email IS NOT NULL OR order_id IS NOT NULL)
        SQL);

        // --- money the organizer already has ---------------------------------

        /*
         * The ledger records what the organizer is owed, and a door sale is
         * money they are holding already — we never touched it and cannot pay
         * out what we did not collect.
         *
         * Recorded rather than omitted, then taken back out: the sale, its
         * tax and its discount are written as they are for any order, and a
         * `collected` entry removes the organizer's share from the balance.
         * The night's gross then still reads as the night's gross, and what we
         * owe is still what we owe.
         */
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN ('sale', 'discount', 'tax', 'refund', 'settlement', 'adjustment', 'collected'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN ('sale', 'discount', 'tax', 'refund', 'settlement', 'adjustment'))
        SQL);

        DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_owner_email_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_buyer_email_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_method_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_channel_check');

        // Rows with nothing to put back belong to sales that only this feature
        // could make; they go with it, on a database being rewound.
        DB::table('tickets')->whereNull('owner_email')->delete();
        DB::table('orders')->whereNull('buyer_email')->delete();

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('owner_email')->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_email')->nullable(false)->change();
            $table->dropIndex(['event_id', 'channel']);
            $table->dropConstrainedForeignId('door_pass_id');
            $table->dropConstrainedForeignId('sold_by_user_id');
            $table->dropColumn(['channel', 'payment_method']);
        });
    }
};
