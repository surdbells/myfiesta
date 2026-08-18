<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory holds, orders, and issued tickets.
 *
 * The order of operations on money is fixed and recorded on every row, so a
 * historic order can always be explained: the discount applies to the subtotal,
 * tax calculates on the discounted amount, and platform commission takes the
 * net. The discount is the organizer's cost, not one the platform shares.
 *
 * Every amount here is computed server-side from ticket_types.price_amount. No
 * endpoint accepts a price. That single rule closes the two findings that let
 * buyers of the previous platform pay whatever they chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A hold reserves stock while the buyer is at the payment step. Taken
        // in the same transaction that increments a code's redemption count, so
        // neither tickets nor a capped code can oversell. Expired rows are
        // swept and the stock returns.
        Schema::create('inventory_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('code_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['ticket_type_id', 'expires_at']);
        });

        DB::statement('ALTER TABLE inventory_holds ADD CONSTRAINT inventory_holds_quantity_check CHECK (quantity > 0)');

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Human-facing, non-enumerable. Shown in emails and support.
            $table->string('reference', 16)->unique();

            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // Null for guest checkout, which is the primary path — buying
            // requires no account. Populated when a guest later claims the
            // order, and pre-populated for migrated historical buyers.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('buyer_email');
            $table->string('buyer_name');
            $table->string('buyer_phone')->nullable();

            // --- money ------------------------------------------------------
            // Currency is snapshotted here rather than read through the event,
            // because an order is an immutable record of what was charged.
            $table->char('currency', 3);
            $table->bigInteger('subtotal_amount');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->bigInteger('commission_amount')->default(0);

            // Which rate applied, so the calculation stays reproducible even
            // after the rate is superseded.
            $table->foreignUuid('tax_rate_id')->nullable()->constrained()->nullOnDelete();

            // --- attribution ------------------------------------------------
            $table->foreignUuid('code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ref_slug')->nullable();

            // --- payment ----------------------------------------------------
            // NGN routes to Paystack, everything else to Stripe. A zero-total
            // order — full-value code, comp, RSVP, free event — has no gateway
            // at all and goes straight to fulfilment.
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->string('idempotency_key')->nullable()->unique();

            $table->string('status')->default('pending');

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();

            $table->index(['event_id', 'status']);
            $table->index(['buyer_email', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index('ref_slug');
            $table->index(['gateway', 'gateway_reference']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_status_check
            CHECK (status IN ('pending', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_gateway_check
            CHECK (gateway IS NULL OR gateway IN ('stripe', 'paystack'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_amounts_check
            CHECK (
                subtotal_amount   >= 0 AND
                discount_amount   >= 0 AND
                tax_amount        >= 0 AND
                total_amount      >= 0 AND
                commission_amount >= 0 AND
                discount_amount  <= subtotal_amount
            )
        SQL);
        // The arithmetic itself, asserted at the storage layer. If application
        // code ever computes a total another way, the write fails rather than
        // producing a row nobody can reconcile.
        DB::statement(<<<'SQL'
            ALTER TABLE orders ADD CONSTRAINT orders_total_arithmetic_check
            CHECK (total_amount = subtotal_amount - discount_amount + tax_amount)
        SQL);

        Schema::create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->restrictOnDelete();

            // Snapshotted: a ticket type may be renamed or repriced afterwards.
            $table->string('ticket_type_name');
            $table->bigInteger('unit_price_amount');
            $table->unsignedInteger('quantity');
            $table->bigInteger('line_total_amount');

            $table->timestampsTz();
            $table->index('order_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_lines ADD CONSTRAINT order_lines_amounts_check
            CHECK (quantity > 0 AND unit_price_amount >= 0
                   AND line_total_amount = unit_price_amount * quantity)
        SQL);

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Cryptographically random, unique. The previous generator used
            // str_shuffle — not a CSPRNG — with no uniqueness constraint.
            // Codes imported from that platform are preserved verbatim so
            // tickets already in inboxes still scan.
            $table->string('code', 32)->unique();

            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained()->restrictOnDelete();

            // Null for comps, RSVPs, and anything issued without payment.
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();

            // Accounts are optional; ownership is not. Every ticket belongs to
            // an identity, claimed or unclaimed. This is what makes transfer an
            // authenticated act rather than a public endpoint keyed on a
            // sequential id, and it is the precondition for controlled resale.
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_email');
            $table->string('holder_name')->nullable();

            $table->string('status')->default('valid');

            $table->timestampTz('checked_in_at')->nullable();
            $table->foreignUuid('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('pdf_path')->nullable();          // path, never a URL
            $table->timestampsTz();

            $table->index(['event_id', 'status']);
            $table->index(['owner_email', 'created_at']);
            $table->index('owner_user_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tickets ADD CONSTRAINT tickets_status_check
            CHECK (status IN ('valid', 'checked_in', 'transferred', 'refunded', 'void'))
        SQL);

        // Every scan attempt, including rejections. The previous endpoint wrote
        // the check-in flag before deciding whether the ticket had already been
        // scanned, kept no log, and could tell two concurrent scanners the same
        // ticket was valid.
        Schema::create('ticket_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scanned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('scanned_code', 32);   // recorded even when unknown
            $table->string('result');             // accepted | duplicate | not_found | wrong_event | void
            $table->timestampTz('scanned_at');
            $table->timestampsTz();

            $table->index(['event_id', 'scanned_at']);
            $table->index('ticket_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_scans ADD CONSTRAINT ticket_scans_result_check
            CHECK (result IN ('accepted', 'duplicate', 'not_found', 'wrong_event', 'void'))
        SQL);

        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('from_email');
            $table->string('to_email');
            $table->foreignUuid('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('transferred_at');
            $table->timestampsTz();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_transfers');
        Schema::dropIfExists('ticket_scans');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('inventory_holds');
    }
};
