<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations own resources. Users hold roles within them.
 *
 * The platform this replaces had no such concept — one account was one
 * organizer, so a venue or promoter collective shared a single password and
 * door staff operated with the owner's credentials. Introducing this later,
 * against live data, is the most expensive change on the roadmap, which is why
 * it lands in the first migration even though its interface ships much later.
 *
 * Every migrated organizer becomes an organization of one, with themselves as
 * owner. Adding a second person is then an invitation, not a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();

            // Brand presence, shown on public event pages.
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();          // path, never a URL
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
            $table->string('x_handle')->nullable();

            // Set once submitted identity documents have been reviewed.
            $table->timestampTz('verified_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // owner    — billing and destructive actions
            // manager  — events, tickets, guests
            // finance  — settlements and payout details
            // marketing— codes, newsletters, attribution
            // door     — the narrowest role: scanning only. Exists so venue staff
            //            can be given access without seeing sales or payouts.
            $table->string('role');

            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        // Roles are a closed set. A typo should fail loudly at write time rather
        // than silently granting nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE organization_user
            ADD CONSTRAINT organization_user_role_check
            CHECK (role IN ('owner', 'manager', 'finance', 'marketing', 'door'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
