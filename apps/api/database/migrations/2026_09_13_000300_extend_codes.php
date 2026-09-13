<?php

use App\Support\Allocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codes that can be aimed, and counted honestly.
 *
 * - Which ticket types a code discounts. "20% off General Admission" was not
 *   expressible, so organizers either discounted VIP by accident or did not
 *   run the promotion.
 * - A minimum number of tickets, for the group deal nightlife runs constantly.
 * - Each order line's share of the discount. A discount that applies to some
 *   lines and not others has to be recorded per line, or a refund of the
 *   undiscounted ticket gives back less than was paid for it.
 * - One tracking slug per organization. Two codes sharing a ?ref= made
 *   attribution a coin toss between them.
 * - redemption_count becomes the count of paid uses, recomputed rather than
 *   incremented. It used to be incremented when checkout began and never given
 *   back, so a 100-use code was exhausted by abandoned checkouts long before
 *   100 people had paid. The ceiling constraint goes with it: a payment that
 *   lands after its hold expired may take a code one past its cap, and
 *   refusing to issue tickets somebody has paid for is the worse outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_ticket_type', function (Blueprint $table) {
            $table->foreignUuid('code_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['code_id', 'ticket_type_id']);
        });

        Schema::table('codes', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_quantity')->nullable()->after('max_per_customer');
        });

        DB::statement('ALTER TABLE codes ADD CONSTRAINT codes_min_quantity_check CHECK (min_quantity IS NULL OR min_quantity >= 1)');

        Schema::table('order_lines', function (Blueprint $table) {
            $table->bigInteger('discount_amount')->default(0)->after('line_total_amount');
        });

        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_discount_check CHECK (discount_amount >= 0 AND discount_amount <= line_total_amount)');

        // Existing orders had one discount across the whole order; spread it
        // over their lines by value, which is exactly how refunds treated it.
        DB::table('orders')->where('discount_amount', '>', 0)->orderBy('id')->each(function (object $order) {
            $lines = DB::table('order_lines')->where('order_id', $order->id)->orderBy('created_at')->orderBy('id')->get();
            $parts = Allocation::split((int) $order->discount_amount, $lines->map(fn ($l) => (int) $l->line_total_amount)->all());

            foreach ($lines->values() as $i => $line) {
                DB::table('order_lines')->where('id', $line->id)->update(['discount_amount' => min($parts[$i], (int) $line->line_total_amount)]);
            }
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX codes_ref_slug_unique
            ON codes (organization_id, lower(ref_slug))
            WHERE ref_slug IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement('ALTER TABLE codes DROP CONSTRAINT IF EXISTS codes_redemption_ceiling_check');

        DB::statement(<<<'SQL'
            UPDATE codes SET redemption_count = (
                SELECT count(*) FROM orders
                WHERE orders.code_id = codes.id AND orders.status IN ('paid', 'partially_refunded')
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS codes_ref_slug_unique');
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT IF EXISTS order_lines_discount_check');

        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('discount_amount'));

        DB::statement('ALTER TABLE codes DROP CONSTRAINT IF EXISTS codes_min_quantity_check');

        Schema::table('codes', fn (Blueprint $table) => $table->dropColumn('min_quantity'));

        Schema::dropIfExists('code_ticket_type');
    }
};
