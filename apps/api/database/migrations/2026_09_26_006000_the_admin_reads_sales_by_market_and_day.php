<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the admin's performance pages and audit log.
 *
 * The dashboards ask one question over and over: what was sold, in this
 * currency, between these two instants. A sale is dated when it was paid, or
 * when it was created for the imported orders that were marked paid without a
 * time, so the index is on that same expression — written exactly as
 * App\Services\Analytics\Metrics writes it, or the planner will not use it —
 * and only over orders that were sales, which is the partial predicate the
 * queries repeat literally.
 *
 * The audit log is read newest first and filtered by action; neither had an
 * index that did not start with something else.
 */
return new class extends Migration
{
    /**
     * Built CONCURRENTLY, which Postgres refuses inside a transaction: a plain
     * CREATE INDEX on orders holds up checkout for as long as it builds.
     */
    public $withinTransaction = false;

    /** @var array<string, string> name => definition */
    private const INDEXES = [
        'orders_sold_by_market_and_time' => "orders (currency, (coalesce(paid_at, created_at))) WHERE status IN ('paid', 'partially_refunded', 'refunded')",
        'orders_sold_by_organization_and_time' => "orders (organization_id, currency, (coalesce(paid_at, created_at))) WHERE status IN ('paid', 'partially_refunded', 'refunded')",
        'refunds_succeeded_by_market_and_time' => "refunds (currency, created_at) WHERE status = 'succeeded'",
        'refunds_organization_id_created_at_index' => 'refunds (organization_id, created_at)',
        'audit_logs_created_at_index' => 'audit_logs (created_at)',
        'audit_logs_action_created_at_index' => 'audit_logs (action, created_at)',
        'sensitive_data_accesses_occurred_at_index' => 'sensitive_data_accesses (occurred_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            // A concurrent build that failed part way leaves an invalid index
            // that IF NOT EXISTS would skip and the planner would never use.
            $invalid = DB::selectOne(
                'SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
                [$name],
            );

            if ($invalid !== null) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
