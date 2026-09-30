<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each row of the old database said when it came across, as a digest.
 *
 * A cutover with a parallel run imports the same database several times while
 * the old app is still taking orders, refunding them and letting organizers
 * edit their nights. A row that came across on Monday can say something else
 * by Friday, and until now a later run saw only that the row was across and
 * passed it by: a refund in the old app after the first import was simply not
 * here, and nothing said so.
 *
 * The import still never overwrites what it made — by then a ledger entry, a
 * refund or a ticket may hang off it — so a later run compares the row with
 * this digest and reports the ones that changed instead (legacy:import).
 *
 * Nullable, because rows imported before this have no digest, and making one
 * up from today's row would hide exactly the changes it is here to find.
 * Only the rows read from a source table carry one; the rows derived from
 * them (an account's organization, a venue, a poster) do not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_map', function (Blueprint $table) {
            // SHA-256 in hex, of the columns the import reads. A digest, never
            // the values: a row can hold a password hash or a ticket code
            // (LegacyRules::fingerprint).
            $table->char('source_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('legacy_map', function (Blueprint $table) {
            $table->dropColumn('source_hash');
        });
    }
};
