<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons: the second thing on the order.
 *
 * This platform has sold exactly one kind of thing since the first migration —
 * a ticket — and nightlife's margin is in the second one. A table comes with
 * two bottles. A cloakroom pass is three dollars and pure profit. A shirt at
 * the merch table is a sale nobody can take through this checkout.
 *
 * Modelled as what it is: a thing with a price and a stock, sold on the same
 * order, settled through the same ledger, and admitting nobody. That last part
 * is the whole distinction. A Table of 6 is a ticket type, because six people
 * walk through a door on it; the bottle on that table is not, because it walks
 * through nothing.
 *
 * So an order line stops being a ticket line. It carries exactly one of the
 * two — the database refuses both and refuses neither — and everything that
 * counts tickets now says so rather than counting lines and hoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Minor units, like every other amount in this system, and read
            // from here at checkout. No request may name a price.
            $table->bigInteger('price_amount');

            // Null is unlimited — twenty tables is a number, a cloakroom is
            // not — and is deliberately not the same as zero.
            $table->unsignedInteger('quantity_available')->nullable();
            $table->unsignedSmallInteger('max_per_order')->nullable();

            // Two states, not four. A ticket type has `hidden` because a
            // presale code can open it; nothing opens an add-on, so closed
            // and invisible are the same thing said twice.
            $table->string('status')->default('on_sale');

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestampsTz();
            // Sold once, referenced for ever: an order line points at it, and
            // an order that cannot name what was bought cannot be explained.
            $table->softDeletesTz();

            $table->index(['event_id', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE add_ons ADD CONSTRAINT add_ons_status_check
            CHECK (status IN ('on_sale', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE add_ons ADD CONSTRAINT add_ons_amounts_check
            CHECK (
                price_amount >= 0
                AND (quantity_available IS NULL OR quantity_available >= 0)
                AND (max_per_order IS NULL OR max_per_order >= 1)
            )
        SQL);

        // --- an order line is no longer only a ticket ------------------------

        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignUuid('add_on_id')->nullable()->after('ticket_type_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->uuid('ticket_type_id')->nullable()->change();
        });

        // The column held "what was bought, as it was called at the time", and
        // that was always the honest reading of it. Now that half the rows
        // name a bottle, the old name is simply wrong.
        Schema::table('order_lines', function (Blueprint $table) {
            $table->renameColumn('ticket_type_name', 'name');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_one_thing_check
            CHECK (num_nonnulls(ticket_type_id, add_on_id) = 1)
        SQL);

        // --- stock is held the same way for both -----------------------------

        Schema::table('inventory_holds', function (Blueprint $table) {
            $table->foreignUuid('add_on_id')->nullable()->after('ticket_type_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('inventory_holds', function (Blueprint $table) {
            $table->uuid('ticket_type_id')->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_holds ADD CONSTRAINT inventory_holds_one_thing_check
            CHECK (num_nonnulls(ticket_type_id, add_on_id) = 1)
        SQL);

        DB::statement('CREATE INDEX inventory_holds_add_on_id_expires_at_index ON inventory_holds (add_on_id, expires_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_holds_add_on_id_expires_at_index');
        DB::statement('ALTER TABLE inventory_holds DROP CONSTRAINT IF EXISTS inventory_holds_one_thing_check');
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT IF EXISTS order_lines_one_thing_check');

        Schema::table('inventory_holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('add_on_id');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('add_on_id');
            $table->renameColumn('name', 'ticket_type_name');
        });

        // Rows written for an add-on have no ticket type to go back to, so
        // they go with it: this only runs on a database being rewound.
        DB::table('order_lines')->whereNull('ticket_type_id')->delete();
        DB::table('inventory_holds')->whereNull('ticket_type_id')->delete();

        Schema::table('order_lines', function (Blueprint $table) {
            $table->uuid('ticket_type_id')->nullable(false)->change();
        });

        Schema::table('inventory_holds', function (Blueprint $table) {
            $table->uuid('ticket_type_id')->nullable(false)->change();
        });

        Schema::dropIfExists('add_ons');
    }
};
