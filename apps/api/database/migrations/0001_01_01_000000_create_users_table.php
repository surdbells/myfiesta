<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One users table for everyone: attendees, organization members, and staff.
 *
 * A person is frequently both — an organizer buys tickets to other people's
 * events — so splitting them would mean two accounts for one human. What makes
 * someone an organizer is membership of an organization, not a separate table.
 *
 * Identifiers are UUIDs throughout this schema. Public-facing resources must
 * not be enumerable: the previous platform keyed ticket transfer on a
 * sequential integer, which is part of why anyone could reassign any ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();

            // Null for unclaimed records. Historical buyers are migrated as
            // unclaimed accounts keyed on the email their tickets were issued
            // to, so when they later sign up their orders are already there.
            $table->string('password')->nullable();

            $table->string('phone')->nullable();
            $table->string('avatar_path')->nullable();      // path, never a URL
            $table->string('locale', 12)->default('en');
            $table->string('timezone')->nullable();

            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('created_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
