<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an organizer needs to know how a night is selling, beyond what sold.
 *
 * How many looked, as a count per event per day and nothing else — no address,
 * no cookie, no visitor, because "how many opened the page" is a question
 * about the page and not about anybody who opened it. And whether an order
 * came through somebody else's website, so the widget can be judged by what
 * it sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_views', function (Blueprint $table) {
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            // Opened inside an organizer's own site rather than on ours.
            $table->unsignedInteger('embed_views')->default(0);

            $table->primary(['event_id', 'day']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('embedded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('embedded'));
        Schema::dropIfExists('event_views');
    }
};
