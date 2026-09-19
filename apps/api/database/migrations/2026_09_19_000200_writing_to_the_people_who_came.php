<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns: an organizer writing to people who are not yet holding a ticket.
 *
 * An event message goes to the people on one night's list. A campaign chooses
 * its own list — who came before, who follows, who got as far as a basket —
 * and usually points at a night they have not bought for yet. The rules about
 * who may be written to live in App\Services\Campaigns\Audiences; this is
 * where what was sent, and to whom, is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The night it is selling, when it is selling one. Nullable: "we
            // are back in March" points at nothing yet.
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 32);
            $table->string('subject', 150);
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('recipients')->nullable();
            // Who the audience held but the rules left out: opted out, or
            // written to by this organizer too recently.
            $table->unsignedInteger('suppressed')->nullable();
            // On the link in the email, and on any order that came through it.
            $table->string('ref', 64)->unique();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'scheduled_for']);
        });

        DB::statement("
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check
            CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled'))
        ");

        DB::statement("
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_audience_check
            CHECK (audience IN ('followers', 'past_attendees', 'abandoned'))
        ");

        // An abandoned basket was a basket for something.
        DB::statement("
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_abandoned_needs_event
            CHECK (audience <> 'abandoned' OR event_id IS NOT NULL)
        ");

        DB::statement("
            ALTER TABLE campaigns ADD CONSTRAINT campaigns_scheduled_has_time
            CHECK (status <> 'scheduled' OR scheduled_for IS NOT NULL)
        ");

        Schema::create('campaign_deliveries', function (Blueprint $table) {
            $table->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            // Denormalised so "written to by this organizer this week" is one
            // indexed read rather than a join through every campaign.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestampTz('created_at');

            $table->primary(['campaign_id', 'email']);
            $table->index(['organization_id', 'email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_deliveries');
        Schema::dropIfExists('campaigns');
    }
};
