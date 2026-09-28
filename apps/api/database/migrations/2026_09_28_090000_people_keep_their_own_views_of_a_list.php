<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A list, filtered the way somebody reads it every morning, kept under a name.
 *
 * "Refunds this week", "Unpaid over $500", "Promoter codes still running":
 * each one a set of filters, a sort and the columns worth seeing, which the
 * console otherwise makes them rebuild every visit. Kept per person and per
 * organization — somebody on two teams reads each one differently, and one
 * person's views are not the team's clutter.
 *
 * The state is the console's to define (SavedViewController checks its shape
 * and size, not its meaning): a view that names a filter the list no longer
 * has simply leaves it off when it is applied, rather than needing a
 * migration every time a list gains or loses one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Which list: "orders", "codes", "guests"… (SavedViewController::LISTS).
            $table->string('list', 40);
            $table->string('name', 60);
            $table->jsonb('state');

            $table->timestampsTz();

            // Two views with one name in one list is never what anybody meant,
            // and the second save of a name replaces the first instead.
            $table->unique(['user_id', 'organization_id', 'list', 'name']);
            $table->index(['user_id', 'organization_id', 'list']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
