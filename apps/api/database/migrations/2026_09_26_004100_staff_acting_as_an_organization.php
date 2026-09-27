<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * myFiesta staff seeing an organization's console as the organization does.
 *
 * Support could read an organization's rows in the admin panel and nothing
 * else, so "my ticket tiers look wrong" was answered by asking for
 * screenshots. This is the record of each time a member of staff opened the
 * console as the organization instead: who, why, for how long, and when it
 * stopped.
 *
 * A session is also the membership the staff member holds for its duration.
 * User::organizations() reads this table in place of organization_user while
 * the request carries the session's token, so every controller that asks
 * "which organization, and in what role?" gets exactly one answer — this one
 * — without being taught about staff at all. That is why the columns are
 * named as a membership's are.
 *
 * The console receives the token in exchange for a short, single-use handoff
 * code, stored hashed like a door pass's secret: the database holds nothing
 * that opens a session, only something to check a presented code against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // The member of staff. Kept as a label as well, like the audit
            // trail's actor: erasing the account must not blank the record of
            // what it did while acting for somebody else.
            $table->foreignUuid('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('staff_label')->nullable();

            // The role the session acts with. Always owner, less what
            // App\Services\Impersonation\WhileImpersonating withholds — a
            // column rather than a constant because this row is read as a
            // membership, and a membership has a role.
            $table->string('role', 20)->default('owner');

            // Why. Required: "looking around" is not a reason to see an
            // organization's buyers.
            $table->text('reason');

            // The handoff: sha256 of the code in the console link, and when it
            // stops working. Single use — exchanged_at is set by the one
            // exchange that succeeds.
            $table->string('handoff_hash', 64)->unique();
            $table->timestampTz('handoff_expires_at');
            $table->timestampTz('exchanged_at')->nullable();

            // The Sanctum token the exchange minted. Not a foreign key: ending
            // the session deletes the token, and this row stays as the record.
            $table->unsignedBigInteger('token_id')->nullable()->unique();

            // Fixed when the session starts, and never extended.
            $table->timestampTz('expires_at');

            $table->timestampTz('ended_at')->nullable();
            $table->foreignUuid('ended_by')->nullable()->constrained('users')->nullOnDelete();
            // ended | signed_out | superseded | expired | unused | staff_role_removed
            $table->string('ended_how', 32)->nullable();

            // Where the session was started from, and where it was opened.
            $table->string('started_ip', 45)->nullable();
            $table->string('opened_ip', 45)->nullable();

            $table->timestampsTz();

            // "What has staff done here lately", asked by the organization's
            // owners; and "what is this person holding open", asked on start.
            $table->index(['organization_id', 'created_at']);
            $table->index(['staff_user_id', 'ended_at']);
        });

        DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_role_check CHECK (role = 'owner')");
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
