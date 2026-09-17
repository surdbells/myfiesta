<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Telling followers about a new night.
 *
 * `announced_at` is what makes it happen once. Unpublishing and republishing
 * an event is a normal thing to do while fixing a typo, and every one of those
 * would otherwise be an email to everybody who follows the organizer.
 *
 * The token on a follow is the way out of that mail for somebody who has no
 * account to sign into — the same shape as leaving a waitlist. It identifies
 * nothing on its own, so a link sitting in forwarded mail leaks nothing about
 * who it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('announced_at')->nullable()->after('published_at');
        });

        Schema::table('organization_follows', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->after('organization_id');
        });

        foreach (DB::table('organization_follows')->whereNull('token')->pluck('id') as $id) {
            DB::table('organization_follows')->where('id', $id)->update(['token' => Str::random(48)]);
        }

        Schema::table('organization_follows', function (Blueprint $table) {
            $table->string('token', 64)->nullable(false)->change();
            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::table('organization_follows', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('announced_at');
        });
    }
};
