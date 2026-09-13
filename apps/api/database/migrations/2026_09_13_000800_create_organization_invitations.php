<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to join an organization.
 *
 * Roles existed from the first migration — owner, manager, finance,
 * marketing, door — and nobody could be given one. A promoter collective, a
 * venue or an agency had one login between the manager, the door, finance and
 * whoever does socials, which is a shared password to a bank payout screen.
 *
 * The token is stored hashed: the database holds nothing that opens the
 * invitation, only something to check a presented one against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_role_check
            CHECK (role IN ('owner', 'manager', 'finance', 'marketing', 'door'))
        SQL);

        // One open invitation per address per organization; inviting again
        // replaces it rather than leaving two links that both work.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organization_invitations_open_unique
            ON organization_invitations (organization_id, lower(email))
            WHERE accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
