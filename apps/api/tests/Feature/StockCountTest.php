<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\Stock;
use Closure;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Counting what is left while somebody else is paying for it.
 *
 * An order that pays inside its hold never counts stock and never takes the
 * row lock that counting does: it becomes paid and gives its hold up in one
 * commit, whenever it likes. Stock used to read what was sold and what was
 * held in two statements, and a commit landing between them was in neither —
 * not yet sold when the first looked, no longer held when the second did. The
 * last table was sold twice.
 *
 * A test cannot run two transactions at once, so the commit is made to land at
 * exactly that moment instead: straight after the first statement that counts.
 */
class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRateSeeder::class);

        $this->event = Event::create([
            'organization_id' => Organization::create(['name' => 'N', 'slug' => 'n-'.uniqid()])->id,
            'slug' => 'e-'.uniqid(),
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    /** Run $sale once, right after the first statement that counts sold or held stock. */
    private function landing(Closure $sale): void
    {
        $landed = false;

        DB::listen(function (QueryExecuted $query) use (&$landed, $sale) {
            $counts = str_contains($query->sql, 'inventory_holds')
                || str_contains($query->sql, 'order_lines')
                || str_contains($query->sql, 'from "tickets"');

            if ($landed || ! $counts || str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                return;
            }

            $landed = true;
            $sale();
        });
    }

    public function test_the_last_add_on_bought_mid_count_is_counted_once(): void
    {
        $general = TicketType::create(['event_id' => $this->event->id, 'name' => 'General', 'price_amount' => 10000, 'status' => 'on_sale']);
        $table = AddOn::create([
            'event_id' => $this->event->id,
            'name' => 'VIP table',
            'price_amount' => 50000,
            'status' => 'on_sale',
            'quantity_available' => 1,
        ]);

        // Somebody holds the only table, and is paying for it.
        $paying = app(CheckoutService::class)->reserve($this->event, [$general->id => 1], 'a@example.com', 'Ada', addOns: [$table->id => 1]);

        // Their payment lands while the next buyer's count is under way.
        $this->landing(function () use ($paying) {
            Order::whereKey($paying->id)->update(['status' => 'paid', 'paid_at' => now()]);
            $paying->holds()->delete();
        });

        $this->assertSame(0, app(Stock::class)->addOnsLeft($table));
    }

    public function test_the_last_ticket_bought_mid_count_is_counted_once(): void
    {
        $type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 10000,
            'status' => 'on_sale',
            'quantity_available' => 2,
        ]);

        $paying = app(CheckoutService::class)->reserve($this->event, [$type->id => 2], 'a@example.com', 'Ada');

        $this->landing(function () use ($paying, $type) {
            Order::whereKey($paying->id)->update(['status' => 'paid', 'paid_at' => now()]);

            foreach (range(1, 2) as $_) {
                Ticket::create([
                    'event_id' => $this->event->id,
                    'ticket_type_id' => $type->id,
                    'order_id' => $paying->id,
                    'owner_email' => 'a@example.com',
                    'code' => strtoupper(Str::random(12)),
                    'status' => 'valid',
                ]);
            }

            $paying->holds()->delete();
        });

        $this->assertSame(0, app(Stock::class)->ticketsLeft($type));
    }
}
