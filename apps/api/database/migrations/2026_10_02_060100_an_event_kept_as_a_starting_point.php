<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An event kept as a starting point for the next one.
 *
 * A template is a night's shape — its details, tiers and prices, extras,
 * questions, reminder times and poster — kept apart from the night itself, so
 * the organizer can change or cancel that night without losing the starting
 * point, and start the next one from the template rather than from whichever
 * past event happens to look most like it (EventTemplates).
 *
 * The shape is one jsonb document (EventBlueprint), not rows of copied tiers:
 * nothing reads into it but the code that makes an event from it, and rows
 * would need every table an event has twice. The poster is copied into files
 * of the template's own (banner_path), because the event it came from may
 * replace or delete its own.
 *
 * Kept by the organization, gone with it. Who saved it is kept for the list
 * and forgotten if their account goes.
 *
 * Which event it was kept from is kept too, while that event exists: a night
 * myFiesta takes off sale must not come back through a template saved the
 * week before (EventTemplateController::createEvent).
 *
 * A name is the organization's once, whatever its capitals: two templates
 * called "Friday" and "friday" would be one choice shown twice. The index
 * holds that when two people save at the same moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->jsonb('payload');
            $table->string('banner_path')->nullable();
            $table->foreignUuid('source_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX event_templates_organization_name_unique ON event_templates (organization_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_templates');
    }
};
