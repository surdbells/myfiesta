<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every member of staff signs in once more, this time with the emailed code.
 *
 * Before the admin asked for a code, its sign-in was the password alone, with
 * a "remember me" box whose cookie lasts 400 days. Those cookies — and any
 * copy of one — would still sign somebody in, and a browser coming back
 * through the remember cookie is not asked for the code: that is the point of
 * the cookie, and it is only safe because the cookie is now issued after the
 * code (App\Filament\Auth\Login). So the ones issued before stop here.
 *
 * A remember cookie works only while it matches the account's remember_token;
 * clearing the token makes every one issued so far worthless, and the next
 * sign-in with the box ticked sets a new one. Session rows are cleared too,
 * where sessions are kept in the database. With any other session store
 * AuthenticateStaff does the same job on the session's next request, because
 * a session signed in before this carries no record of the code.
 *
 * Nothing to undo: nobody can be signed back in by putting a token back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $staff = DB::table('users')->whereNotNull('platform_role')->select('id');

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $staff)->delete();
        }

        DB::table('users')->whereNotNull('platform_role')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        // See above.
    }
};
