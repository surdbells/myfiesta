<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presale: codes that unlock tickets.
 *
 * A tier is locked while it is hidden, or before its sales open. An access
 * code names the tiers it opens, and a buyer holding it can see and buy them
 * early — the presale every nightlife promoter runs for their list, which
 * until now meant emailing a secret link that did not exist: a hidden tier
 * could not be bought by anybody.
 *
 * A code may still discount and credit a promoter as well; unlocking is a
 * third thing it can do, so the "does something" constraint gains it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_unlocks', function (Blueprint $table) {
            $table->foreignUuid('code_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['code_id', 'ticket_type_id']);
        });

        Schema::table('codes', function (Blueprint $table) {
            // Kept in step with code_unlocks by the controller; a check
            // constraint cannot look into another table.
            $table->boolean('unlocks_tickets')->default(false)->after('ref_slug');
        });

        DB::statement('ALTER TABLE codes DROP CONSTRAINT codes_purposeful_check');
        DB::statement(<<<'SQL'
            ALTER TABLE codes ADD CONSTRAINT codes_purposeful_check
            CHECK (discount_type IS NOT NULL OR ref_slug IS NOT NULL OR unlocks_tickets)
        SQL);

        Schema::table('orders', function (Blueprint $table) {
            // The code that let this order buy a locked tier. Separate from
            // code_id, the discount: a buyer can arrive with a presale code and
            // a different discount code, and both have limits to count against.
            $table->foreignUuid('access_code_id')->nullable()->after('code_id')->constrained('codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('access_code_id'));

        DB::statement('ALTER TABLE codes DROP CONSTRAINT codes_purposeful_check');
        DB::statement('ALTER TABLE codes ADD CONSTRAINT codes_purposeful_check CHECK (discount_type IS NOT NULL OR ref_slug IS NOT NULL)');

        Schema::table('codes', fn (Blueprint $table) => $table->dropColumn('unlocks_tickets'));

        Schema::dropIfExists('code_unlocks');
    }
};
