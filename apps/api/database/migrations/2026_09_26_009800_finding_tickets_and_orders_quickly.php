<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the questions the busiest paths ask.
 *
 * Postgres does not index a foreign key by itself, and most of the ones below
 * were declared with constrained() and nothing else — so "the tickets on this
 * order" read every ticket on the platform, on the page a buyer opens from
 * their confirmation email and on every refund. None of it shows with a
 * hundred rows; all of it shows the week the old platform's tickets arrive.
 *
 * What was already covered, and so is not here: a door scan finds its ticket
 * by code (unique); the door list and guest list read tickets by event and
 * status; an order is found by reference and by its access token (both
 * unique); a hold is counted by ticket type or add-on and expiry; scans are
 * read by event and time and by ticket.
 */
return new class extends Migration
{
    /**
     * Built CONCURRENTLY, which Postgres refuses inside a transaction.
     *
     * A plain CREATE INDEX blocks every write to the table while it builds.
     * Migrations run while the previous release is still serving, and against
     * tickets or orders that is checkout standing still for as long as the
     * build takes — during an on-sale, the one time anybody notices.
     */
    public $withinTransaction = false;

    /**
     * Name => [table, columns an existing index would already cover, definition].
     *
     * Columns are null for an expression or a partial index, which no other
     * index could stand in for.
     *
     * @var array<string, array{string, list<string>|null, string}>
     */
    private const INDEXES = [
        // The tickets on an order: the buyer's ticket page, the order status
        // page, every refund, and fulfilment checking it has not already run.
        'tickets_order_id_index' => ['tickets', ['order_id'], 'tickets (order_id)'],

        // How many of a tier are sold, counted under a lock on every quote
        // and every order. The lock queues buyers behind this count.
        'tickets_ticket_type_id_status_index' => ['tickets', ['ticket_type_id', 'status'], 'tickets (ticket_type_id, status)'],

        // Orders by buyer, as every query that asks actually spells it —
        // lower(buyer_email). The plain buyer_email index is no use to them.
        // The organizer's order list asks this for every address on the page,
        // and a privacy request asks it for one.
        'orders_lower_buyer_email_created_at_index' => ['orders', null, 'orders (lower(buyer_email), created_at)'],

        // Baskets nobody paid for, swept every fifteen minutes. Only pending
        // orders are ever in it, so it stays the size of the last two hours.
        'orders_pending_created_at_index' => ['orders', null, "orders (created_at) WHERE status = 'pending'"],

        // A code's uses, counted under the code's lock at checkout and again
        // on every payment and refund. Most orders have no code, so only the
        // ones that do are in it.
        'orders_code_id_index' => ['orders', null, 'orders (code_id) WHERE code_id IS NOT NULL'],
        'orders_access_code_id_index' => ['orders', null, 'orders (access_code_id) WHERE access_code_id IS NOT NULL'],

        // An account's orders, and what erasing an account has to find.
        'orders_user_id_index' => ['orders', null, 'orders (user_id) WHERE user_id IS NOT NULL'],

        // What was sold of a tier or an add-on. Add-ons are counted from
        // order lines alone, at checkout, under a lock.
        'order_lines_ticket_type_id_index' => ['order_lines', ['ticket_type_id'], 'order_lines (ticket_type_id)'],
        'order_lines_add_on_id_index' => ['order_lines', null, 'order_lines (add_on_id) WHERE add_on_id IS NOT NULL'],

        // The daily sweep of holds more than a day expired. The existing
        // indexes lead with the ticket type, which this does not know.
        'inventory_holds_expires_at_index' => ['inventory_holds', ['expires_at'], 'inventory_holds (expires_at)'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns, $definition]) {
            $this->dropIfInvalid($name);

            // Somebody else's migration may have indexed the same columns
            // under another name. Two identical indexes are twice the write
            // cost for no read benefit.
            if ($columns !== null && ! Schema::hasIndex($table, $name) && Schema::hasIndex($table, $columns)) {
                continue;
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

    /**
     * A concurrent build that fails part way leaves its index behind, marked
     * invalid: present, so IF NOT EXISTS skips it on the next run, and never
     * used, so the query it was for stays slow with nothing to say why.
     */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
            [$name],
        );

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
