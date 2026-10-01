<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where else to find an organizer: TikTok, and a website of their own.
 *
 * Instagram, Facebook and X already have columns, filled by the legacy
 * importer with whatever the old platform held: a full address one time, a
 * username with an at sign in front the next. Those are read through Socials,
 * which makes sense of either, rather than rewritten here. A migration that
 * guessed wrong would lose the original, and the reader can simply decline to
 * show what it cannot read.
 *
 * Both new columns hold what Socials writes: a TikTok username without its @,
 * and a website as an https address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tiktok', 64)->nullable()->after('x_handle');
            $table->string('website', 255)->nullable()->after('tiktok');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['tiktok', 'website']);
        });
    }
};
