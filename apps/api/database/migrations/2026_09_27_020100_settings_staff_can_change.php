<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own settings, changed by staff rather than by a deploy.
 *
 * The service charge, who the seller on a receipt is, whether Quebec's QST is
 * collected and the numbers printed under a tax line are business decisions,
 * and each of them used to wait for somebody to edit an environment file and
 * restart the servers. A row here overrides the configured default; no row
 * means the default still applies, so a fresh install behaves exactly as the
 * environment says until somebody decides otherwise.
 *
 * One row per setting, keyed by name, with the value as JSON so a number stays
 * a number and a switch stays a switch. Who last changed it is kept here for
 * the settings page; what it was before is kept in the audit log, which is the
 * record that cannot be edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
