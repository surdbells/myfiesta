<?php

use App\Support\RichText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring descriptions already in the table under the same rule as new ones.
 *
 * The model now cleans a description on every write, but rows written before
 * that — typed into a plain textarea, or copied raw from the legacy platform's
 * WYSIWYG — never passed through it. Plain text becomes paragraphs; HTML is
 * sanitized to the allowlist.
 *
 * Through the query builder rather than the model, so updated_at is left
 * alone: nobody edited these events, and a timestamp saying otherwise would
 * be a lie in the audit trail. Safe to run twice — cleaning clean HTML
 * returns it unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->whereNotNull('description')
            ->orderBy('id')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $clean = RichText::clean($event->description);

                    if ($clean !== $event->description) {
                        DB::table('events')->where('id', $event->id)->update(['description' => $clean]);
                    }
                }
            });
    }

    /** Sanitizing removes information on purpose; there is nothing to restore. */
    public function down(): void {}
};
