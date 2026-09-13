<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Door passes: a link that turns one phone into a scanner for one event.
 *
 * The `door:{event_id}` token ability has existed since the API was designed,
 * and the middleware and controller honour it — but nothing ever minted one.
 * So the only way to put somebody on the door was to make them a member of the
 * organization, with an account and a password, for one night's work.
 *
 * A pass is that token, handed over as a link. The link's secret is stored
 * hashed and works once: the phone that opens it gets the token, and a
 * forwarded copy opened later gets nothing. Scans made with it carry the pass,
 * so the organizer can see which door let whom in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_passes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            // "Front gate", "Tunde" — whatever tells the organizer which phone.
            $table->string('label', 60);
            $table->string('secret_hash', 64)->unique();
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            // The Sanctum token minted on claim. Not a foreign key: revoking
            // deletes the token, and the pass stays as the record of it.
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('ticket_scans', function (Blueprint $table) {
            $table->foreignUuid('door_pass_id')->nullable()->after('scanned_by')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('door_pass_id');
        });

        Schema::dropIfExists('door_passes');
    }
};
