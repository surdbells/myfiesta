<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asking the people who came what they thought.
 *
 * A template is a list of questions: myFiesta's own (no organization), or one
 * an organizer wrote. A night's survey copies the questions it was sent with,
 * so editing a template later never changes what somebody was asked, nor how
 * their answers are read.
 *
 * On by default, for every night: an organization can turn surveys off for
 * all its events, and any one event can be turned off on its own.
 *
 * One invitation per address per night, enforced here rather than trusted to
 * the sender: two runs at once, or a run that died halfway and was started
 * again, find the unique index and write to nobody twice. One answer per
 * invitation, for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null for myFiesta's own, which every organization can use.
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // [{id, type, label, options, required}], twelve at most.
            $table->jsonb('questions');
            // Kept rather than deleted: a night sent with it is compared with
            // the next night sent with it, and that needs the row.
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'archived_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('surveys_enabled')->default(true);
        });

        Schema::create('event_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->unique()->constrained()->cascadeOnDelete();
            // Null means myFiesta's own template.
            $table->foreignUuid('template_id')->nullable()->constrained('survey_templates')->nullOnDelete();
            // The questions as they were sent. Follows the template until then.
            $table->jsonb('questions');
            $table->boolean('enabled')->default(true);
            // Hours after the night's end. The door's last scans have come in
            // well before the default, and the night is still fresh.
            $table->unsignedSmallInteger('send_delay_hours')->default(18);
            $table->timestampTz('sent_at')->nullable();
            // Somebody who pressed "Send now" rather than the hourly run.
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['template_id', 'sent_at']);
        });

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_survey_id')->constrained()->cascadeOnDelete();
            // The link's whole credential, like a ticket link's.
            $table->string('token', 64)->unique();
            // Lowercased on the way in, so one person is one invitation.
            $table->string('email');
            // Claimed just before its email is queued: a run that finds it
            // set leaves it alone.
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['event_survey_id', 'email']);
        });

        // An erasure finds these by address (config/personal_data.php), and
        // matches addresses as lower(email).
        DB::statement('CREATE INDEX survey_invitations_lower_email_index ON survey_invitations (lower(email))');

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invitation_id')->unique()->constrained('survey_invitations')->cascadeOnDelete();
            $table->foreignUuid('event_survey_id')->constrained()->cascadeOnDelete();
            // {question id: answer}. No address and no name: those stay on
            // the invitation, which the organizer is never shown.
            $table->jsonb('answers');
            $table->timestampTz('created_at')->nullable();

            $table->index('event_survey_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_invitations');
        Schema::dropIfExists('event_surveys');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('surveys_enabled');
        });

        Schema::dropIfExists('survey_templates');
    }
};
