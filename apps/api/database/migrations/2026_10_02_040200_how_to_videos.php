<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Short videos on how to buy, get in, and run a night: help/videos.
 *
 * Hosted on YouTube and named here by the video's id alone, never by a whole
 * address. The site builds the address itself, on the no-cookie domain, so
 * nothing typed into the admin decides where the page sends a reader.
 *
 * A video is a draft until somebody on staff publishes it, so one can be added
 * and checked before buyers see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 120);
            // The eleven characters after watch?v= — HelpVideo::YOUTUBE_ID.
            $table->string('youtube_id', 11);
            $table->string('description', 500)->nullable();
            // Who it is for: somebody buying a ticket, or somebody selling them.
            $table->string('audience', 20);
            // Lower first. Ties fall back to the title.
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestampsTz();

            $table->index(['published', 'audience', 'sort_order']);
        });

        // The same rules the admin form applies, for anything that does not
        // come through it. An id with a slash or a quote in it would end up in
        // an address and an attribute on a public page.
        DB::statement(<<<'SQL'
            ALTER TABLE help_videos ADD CONSTRAINT help_videos_youtube_id_check
            CHECK (youtube_id ~ '^[A-Za-z0-9_-]{11}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE help_videos ADD CONSTRAINT help_videos_audience_check
            CHECK (audience IN ('buyers', 'organizers'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('help_videos');
    }
};
