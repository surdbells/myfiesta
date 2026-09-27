<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The indexes the busiest paths depend on.
 *
 * Nothing fails without them — every query still answers, just by reading the
 * whole table. Which is invisible until there are tickets in it, so the only
 * place to notice one going missing is here.
 */
class HotQueryIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hot_paths_have_their_indexes(): void
    {
        $expected = [
            // A buyer's tickets, a refund, fulfilment.
            'tickets' => ['tickets_order_id_index', 'tickets_ticket_type_id_status_index'],
            // Orders by buyer, the abandoned-basket sweep, a code's uses, an
            // account's orders.
            'orders' => [
                'orders_lower_buyer_email_created_at_index',
                'orders_pending_created_at_index',
                'orders_code_id_index',
                'orders_access_code_id_index',
                'orders_user_id_index',
            ],
            // What was sold of a tier or an add-on.
            'order_lines' => ['order_lines_ticket_type_id_index', 'order_lines_add_on_id_index'],
            // The daily sweep of expired holds.
            'inventory_holds' => ['inventory_holds_expires_at_index'],
        ];

        foreach ($expected as $table => $indexes) {
            foreach ($indexes as $index) {
                $this->assertTrue(Schema::hasIndex($table, $index), "{$table} is missing {$index}.");
            }
        }
    }

    public function test_every_one_of_them_is_usable(): void
    {
        // A concurrent build that failed half way leaves an index Postgres
        // never uses. Present and invalid is worse than absent: nothing says.
        $invalid = DB::select(<<<'SQL'
            SELECT c.relname FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_class t ON t.oid = i.indrelid
            WHERE NOT i.indisvalid AND t.relname IN ('tickets', 'orders', 'order_lines', 'inventory_holds', 'ticket_scans')
        SQL);

        $this->assertSame([], $invalid);
    }

    public function test_orders_by_buyer_are_found_by_the_expression_the_code_uses(): void
    {
        // lower(buyer_email) is how every lookup by address is written. An
        // index on the bare column would be skipped by all of them.
        $definition = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'orders_lower_buyer_email_created_at_index'"
        )->indexdef;

        $this->assertStringContainsString('lower((buyer_email)::text)', $definition);
    }
}
