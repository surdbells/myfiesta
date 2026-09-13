<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price ladders: a tier that opens when the one before it sells out.
 *
 * Early Bird at 20, then Tier 1 at 30 the moment Early Bird is gone, then
 * Tier 2 at 40. Every nightlife on-sale runs this way, and the only way to do
 * it here was to sit watching the count and flip the next tier on by hand —
 * at the exact moment demand peaks, which is when nobody is at a laptop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->foreignUuid('opens_after_id')->nullable()->after('sort_order')->constrained('ticket_types')->nullOnDelete();
        });

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_opens_after_not_self CHECK (opens_after_id IS NULL OR opens_after_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ticket_types DROP CONSTRAINT IF EXISTS ticket_types_opens_after_not_self');

        Schema::table('ticket_types', fn (Blueprint $table) => $table->dropConstrainedForeignId('opens_after_id'));
    }
};
