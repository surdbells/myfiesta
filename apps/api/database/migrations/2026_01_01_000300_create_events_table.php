<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events, ticket types, and the venues they reuse.
 *
 * Two conventions established here and followed everywhere after:
 *
 *   Money is a pair. An integer amount in minor units — cents, kobo — and a
 *   currency. Never a float, never a bare number. An event sells in exactly one
 *   currency, so ticket prices inherit it rather than repeating it per row.
 *
 *   Time is stored with a zone, and each event carries its own IANA timezone.
 *   The previous platform hardcoded one timezone for the whole system, which
 *   was already wrong for a Toronto business and is unworkable once Lagos
 *   events exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address_line')->nullable();
            $table->string('city');
            $table->string('subdivision', 8)->nullable();   // drives the tax lookup
            $table->char('country', 2);
            $table->string('timezone');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'name']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('venue_id')->nullable()->constrained()->nullOnDelete();

            // Routed at the root: myfiesta.ca/{slug}. Slugs from the previous
            // platform are imported verbatim — those links are in shared
            // messages, bios, and printed QR codes, and cannot be edited.
            $table->string('slug')->unique();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('poster_path')->nullable();       // path, never a URL

            // An event sells in one currency. Ticket prices inherit it.
            $table->char('currency', 3);

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('timezone');                       // IANA, per event

            // Denormalised from the venue so an event can exist without one,
            // and so tax resolution does not depend on a nullable join.
            $table->string('city');
            $table->string('subdivision', 8)->nullable();
            $table->char('country', 2);

            $table->string('category')->nullable();
            $table->string('dress_code')->nullable();

            // The previous schema declared an identity-required flag and never
            // read it. Venues in both markets have real age requirements.
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->boolean('id_required')->default(false);

            $table->string('status')->default('draft');
            $table->boolean('is_featured')->default(false);

            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'starts_at']);
            $table->index(['country', 'city', 'starts_at']);
            $table->index(['organization_id', 'starts_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE events ADD CONSTRAINT events_status_check
            CHECK (status IN ('draft', 'review', 'scheduled', 'published', 'cancelled'))
        SQL);
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_end_after_start_check CHECK (ends_at IS NULL OR ends_at > starts_at)');

        // Full-text search. Postgres covers this without a separate search
        // service, which is a large part of why it was chosen.
        DB::statement(<<<'SQL'
            ALTER TABLE events ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(city, '')), 'B') ||
                setweight(to_tsvector('simple', coalesce(description, '')), 'C')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX events_search_idx ON events USING GIN (search_vector)');

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Authoritative. Clients send quantities; they never send prices.
            // Currency comes from the event. The previous checkout took the
            // amount from the request body, so buyers set their own price.
            $table->bigInteger('price_amount');

            $table->unsignedSmallInteger('admits')->default(1);

            // Null means unlimited. Enforced at purchase inside the same
            // transaction as the hold — the previous system stored both of
            // these and consulted neither.
            $table->unsignedInteger('quantity_available')->nullable();
            $table->unsignedSmallInteger('max_per_order')->nullable();

            $table->timestampTz('sales_start_at')->nullable();
            $table->timestampTz('sales_end_at')->nullable();

            $table->string('status')->default('on_sale');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['event_id', 'status', 'sort_order']);
        });

        DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_price_check CHECK (price_amount >= 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE ticket_types ADD CONSTRAINT ticket_types_status_check
            CHECK (status IN ('on_sale', 'sold_out', 'hidden', 'closed'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('events');
        Schema::dropIfExists('venues');
    }
};
