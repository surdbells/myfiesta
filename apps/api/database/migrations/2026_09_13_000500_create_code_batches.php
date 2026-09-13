<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batches of single-use codes.
 *
 * For a sponsor's two hundred giveaway tickets, a radio station's winners, a
 * staff allocation: one code per person, each good once, handed out as a
 * spreadsheet. Making those one at a time on the codes screen was two hundred
 * trips through a form, so organizers made one shared code instead — and
 * a shared code posted in a group chat is two hundred tickets for whoever
 * reads the group chat.
 *
 * The batch holds what its codes have in common and how many were made; each
 * code is still an ordinary code row, so checkout, limits, refunds and sales
 * reporting treat them exactly like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 120);
            $table->string('prefix', 12);
            $table->unsignedInteger('quantity');

            $table->timestampsTz();

            $table->index(['event_id', 'created_at']);
        });

        Schema::table('codes', function (Blueprint $table) {
            $table->foreignUuid('batch_id')->nullable()->after('event_id')->constrained('code_batches')->nullOnDelete();
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('codes', fn (Blueprint $table) => $table->dropConstrainedForeignId('batch_id'));

        Schema::dropIfExists('code_batches');
    }
};
