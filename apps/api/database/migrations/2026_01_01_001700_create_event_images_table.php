<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures belonging to an event.
 *
 * One table for the banner and the gallery, because they differ in how many
 * there are and almost nothing else: both are uploaded, validated, resized,
 * stored and deleted the same way. Two tables would mean two of each of those,
 * and the second copy is always the one that misses a fix.
 *
 * events.poster_path goes away with this. An event's banner living in a column
 * *and* in a row is two answers to one question, and the two drift the first
 * time something writes only one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('kind');

            /*
             * Paths, never URLs.
             *
             * A URL bakes today's host into a row that outlives it — the disk
             * moves from local to R2 and every stored URL is wrong. The path is
             * what is stable; the URL is derived at read time from whichever
             * disk is configured.
             *
             * Renditions live in a JSON map rather than a column each, because
             * they are always read together, never queried individually, and
             * the useful set will change: a banner needs an Open Graph crop
             * that a gallery photo does not.
             */
            $table->string('path');
            $table->json('renditions')->nullable();

            /*
             * Nullable, and only for one reason.
             *
             * Anything uploaded through this platform is measured as it is
             * processed, so these are always known. A row imported from the
             * previous platform knows its path and nothing else until something
             * opens the file — and recording a made-up 1×1 to satisfy a NOT NULL
             * would put a lie in the data that reads exactly like a real
             * measurement.
             */
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->string('mime')->nullable();

            // Shown under a gallery photo, and used as alt text. The same
            // string doing both is deliberate: a caption an organizer actually
            // writes is worth more to a screen reader than an alt field they
            // leave empty.
            $table->string('caption', 255)->nullable();

            $table->unsignedInteger('position')->default(0);

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['event_id', 'kind', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE event_images ADD CONSTRAINT event_images_kind_check
            CHECK (kind IN ('banner', 'gallery'))
        SQL);

        // One banner per event, enforced here rather than by whoever remembers.
        // Partial, so it says nothing about how many gallery images there are.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX event_images_one_banner
            ON event_images (event_id) WHERE kind = 'banner'
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE event_images ADD CONSTRAINT event_images_dimensions_check
            CHECK ((width IS NULL OR width > 0)
                   AND (height IS NULL OR height > 0)
                   AND (byte_size IS NULL OR byte_size > 0))
        SQL);

        /*
         * The old column's contents become the banner row.
         *
         * Nothing has written it yet, so this is empty today — but it is the
         * column an import from the previous platform was going to land in, and
         * a migration that silently drops data because it happens to be empty
         * now is one that destroys data the day it is not.
         */
        DB::statement(<<<'SQL'
            INSERT INTO event_images
                (id, event_id, kind, path, position, created_at, updated_at)
            SELECT gen_random_uuid(), id, 'banner', poster_path, 0, now(), now()
            FROM events
            WHERE poster_path IS NOT NULL AND poster_path <> ''
        SQL);

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('poster_path')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE events SET poster_path = i.path
            FROM event_images i
            WHERE i.event_id = events.id AND i.kind = 'banner'
        SQL);

        Schema::dropIfExists('event_images');
    }
};
