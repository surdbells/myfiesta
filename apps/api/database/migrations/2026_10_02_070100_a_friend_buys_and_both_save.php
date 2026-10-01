<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Friend buys, both save.
 *
 * An organizer offers a share off a night (events.share_discount_bps). Each
 * buyer gets a link of their own (share_links); a friend who buys through it
 * pays less, through a hidden code the link stands for, and once the friend
 * has paid the buyer is sent a single-use code worth the same off their next
 * tickets from that organizer (share_rewards) — up to share_max_rewards of
 * them for one link. The organizer pays for both, out of the night's
 * proceeds, as with any discount they offer.
 *
 * The codes stay codes, so pricing, limits, recounting and the ledger treat
 * them exactly as they treat an organizer's own. `purpose` is what tells
 * them apart: a share_friend code is never typed by anybody (the link is the
 * only way to it) and a share_reward code belongs to one person, so neither
 * is listed among the organizer's own codes unless asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Null is no offer. Basis points of the ticket price, as codes are.
            $table->integer('share_discount_bps')->nullable();
            // How many friends' orders one link earns its holder a reward for.
            $table->smallInteger('share_max_rewards')->default(5);
        });

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_share_discount_check CHECK (share_discount_bps IS NULL OR (share_discount_bps > 0 AND share_discount_bps <= 10000))');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_share_max_rewards_check CHECK (share_max_rewards >= 1)');

        Schema::table('codes', function (Blueprint $table) {
            $table->string('purpose', 16)->default('promo');
        });

        DB::statement("ALTER TABLE codes ADD CONSTRAINT codes_purpose_check CHECK (purpose IN ('promo', 'share_friend', 'share_reward'))");

        // One hidden code behind a night's offer. Two would make which of them
        // a friend's link resolves to a coin toss.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX codes_one_share_friend_per_event
            ON codes (event_id)
            WHERE purpose = 'share_friend' AND deleted_at IS NULL
        SQL);

        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Whose link it is: an address, lowercased, because most buyers
            // have no account — and the account when there is one.
            $table->string('owner_email');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            // The paid order it was first issued for. Null for one a ticket
            // holder asked for from the phone.
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();

            // What rides on ?ref=: 'f' and ten base32 characters.
            $table->string('slug', 16)->unique();

            // Rewards standing (issued, not voided), against share_max_rewards.
            $table->unsignedSmallInteger('reward_count')->default(0);

            $table->timestampTz('created_at')->useCurrent();

            // One link per person per night: asking twice gets the same one.
            $table->unique(['event_id', 'owner_email']);
            $table->index('owner_email');
        });

        Schema::create('share_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();
            // One reward per friend's order, however often its payment is
            // announced.
            $table->foreignUuid('friend_order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('code_id')->constrained('codes')->cascadeOnDelete();
            $table->string('status', 16)->default('issued');
            $table->timestampsTz();

            $table->index('share_link_id');
        });

        DB::statement("ALTER TABLE share_rewards ADD CONSTRAINT share_rewards_status_check CHECK (status IN ('issued', 'voided'))");

        Schema::table('orders', function (Blueprint $table) {
            // The friend's link this order was discounted through, if any.
            $table->foreignUuid('share_link_id')->nullable()->constrained()->nullOnDelete();
            $table->index('share_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('share_link_id');
        });

        Schema::dropIfExists('share_rewards');
        Schema::dropIfExists('share_links');

        DB::statement('DROP INDEX IF EXISTS codes_one_share_friend_per_event');
        DB::statement('ALTER TABLE codes DROP CONSTRAINT IF EXISTS codes_purpose_check');

        Schema::table('codes', fn (Blueprint $table) => $table->dropColumn('purpose'));

        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_share_max_rewards_check');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_share_discount_check');

        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['share_discount_bps', 'share_max_rewards']));
    }
};
