<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name that was actually checked.
 *
 * The tick beside an organizer means somebody read their identity documents
 * and agreed they are who they say. Until organizers could edit their own
 * name, that held. Now they can — and a verified "Lagos Nights" renaming
 * itself to a household name and keeping the tick is the whole value of the
 * tick, handed away.
 *
 * So the name is recorded at the moment of verification, and the public tick
 * is shown only while the two still match. The verification itself is not
 * destroyed by a rename: staff confirm the new name in the admin panel, which
 * is a glance rather than the organizer re-uploading a passport over a typo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('verified_name', 120)->nullable()->after('verified_at');
        });

        // Everything verified until now was verified under the name it has.
        DB::table('organizations')
            ->whereNotNull('verified_at')
            ->update(['verified_name' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('verified_name');
        });
    }
};
