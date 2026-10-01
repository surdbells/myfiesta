<?php

use Database\Seeders\SurveyTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * myFiesta's own survey, installed by a deploy.
 *
 * Production runs `migrate`, not `db:seed`, and every night is sent this
 * survey unless its organizer chose another — so without it here, no night
 * would be surveyed at all until somebody remembered to seed it. The
 * questions themselves are SurveyTemplateSeeder's.
 *
 * Idempotent: added only when there is none. Installed under the test suite
 * too, because every night falls back to it there as well.
 *
 * No down(): nights already sent with it point at the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SurveyTemplateSeeder)->install();
    }

    public function down(): void
    {
        //
    }
};
