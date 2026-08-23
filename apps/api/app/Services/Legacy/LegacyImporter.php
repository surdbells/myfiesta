<?php

namespace App\Services\Legacy;

use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Payments\GatewayFee;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moving the live database into this one.
 *
 * Reads from a MySQL connection named `legacy`, in dependency order, writing
 * through the same models the application uses rather than raw inserts — so
 * every check constraint, every generated reference, every cast applies to
 * migrated rows exactly as it does to new ones. An import that bypasses them
 * produces a database that passes its own migration and fails the first time
 * somebody uses it.
 *
 * Two properties matter more than speed here:
 *
 *   Re-runnable. Every row is looked up in legacy_map before it is created.
 *   This will be interrupted — it is a quarter of a gigabyte of poster blobs
 *   across a network — and the recovery has to be `run it again`.
 *
 *   Honest about what it invented. Events have no end time in the source and
 *   most have no timezone; both are inferred, both are recorded as inferred,
 *   and the run reports the count at the end rather than leaving somebody to
 *   discover it from a support ticket.
 *
 * What is deliberately not imported: the `transactions` table (19 rows of
 * hand-written narration that is not a ledger), `blogs`, `newsletter`,
 * `featured`, `notes`, and the various lookup tables whose values are already
 * denormalised into the rows that reference them.
 */
class LegacyImporter
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly LegacyMap $map,
        private readonly ?\Closure $progress = null,
    ) {}

    private function legacy(): ConnectionInterface
    {
        return DB::connection('legacy');
    }

    /**
     * @return array{counts: array<string, int>, notes: list<string>}
     */
    public function run(): array
    {
        // Order is dependency order, and each stage commits on its own. One
        // transaction around the whole run would hold locks for as long as the
        // import takes and lose everything to a single bad row two hours in.
        $this->importOrganizers();
        $this->importEvents();
        $this->importTicketTypes();
        $this->importOrders();
        $this->importTickets();
        $this->importSettlements();

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    /**
     * Accounts, and the organizations they imply.
     *
     * The old system has no organizations table. It has `user_accounts` with a
     * `user_type` of 'event_org', and events that name an organizer by that
     * account's id in a varchar column. So an organization is derived here: one
     * per organizer, named for the account, with that account as its owner.
     *
     * That is the shape the platform already had — an organizer is a person
     * who happens to run events — and inventing anything richer at import time
     * would be inventing facts nobody recorded.
     */
    private function importOrganizers(): void
    {
        $rows = $this->legacy()->table('user_accounts')->orderBy('id')->get();

        foreach ($rows as $row) {
            $this->tick('user_accounts');

            if ($this->map->find('user_accounts', $row->id)) {
                continue;
            }

            $password = LegacyRules::importablePassword($row->password ?? null);
            $inferred = [];

            if ($password === null) {
                // SHA-1, or something unrecognised. The account comes across
                // and the person resets on their next sign-in.
                $inferred['password'] = 'not importable; reset required';
            }

            $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

            $user = User::create([
                'name' => $name === '' ? (string) $row->email_address : $name,
                'email' => strtolower(trim((string) $row->email_address)),
                // A random secret rather than null: the column is not nullable,
                // and a value nobody knows is a password nobody can use. The
                // real hash, where there is one, is written below.
                'password' => Str::random(64),
                'phone' => $row->phone_number ?: null,
                // Every account in the source was created before this import
                // and has been signing in, so the address is as verified as it
                // was going to get. Requiring re-verification at cutover would
                // lock out the entire user base on day one.
                'email_verified_at' => $row->_registered ?? now(),
                'created_at' => $row->_registered ?? now(),
            ]);

            if ($password !== null) {
                // Written past the model, because `password` is cast to
                // `hashed` and that cast re-validates an existing hash against
                // this application's current cost policy — rejecting anything
                // computed at a higher cost than we are configured for, which
                // is exactly what an older system's hashes are.
                //
                // A legacy hash is a fact about a password somebody already
                // has. It is stored as it was, and Laravel rehashes it to the
                // current cost the first time they sign in.
                DB::table('users')->where('id', $user->id)->update(['password' => $password]);
            }

            $this->map->record('user_accounts', $row->id, 'user', $user->id, $inferred);

            $organization = Organization::create([
                'name' => $name === '' ? (string) $row->email_address : $name,
                'slug' => $this->uniqueSlug(
                    Organization::class,
                    LegacyRules::slugify($name, 'organizer-'.$row->id),
                ),
                'contact_email' => strtolower(trim((string) $row->email_address)),
                'contact_phone' => $row->phone_number ?: null,
                'created_at' => $row->_registered ?? now(),
            ]);

            $organization->members()->attach($user->id, [
                'role' => 'owner',
                'accepted_at' => $row->_registered ?? now(),
            ]);

            // Keyed by the same legacy id: events name their organizer by the
            // account id, and this is what turns that into an organization.
            $this->map->record('organization_for_account', $row->id, 'organization', $organization->id);
            $this->tick('organizations');
        }
    }

    /**
     * Events.
     *
     * The two things the source cannot answer are handled here rather than
     * skipped: an event has no end time at all, and `_timezone` is usually
     * null. Both are inferred and both are recorded as inferred.
     *
     * Poster blobs are not touched. `events._poster` is a longblob holding
     * 288 MB across 286 rows — 96% of the entire database — and moving image
     * bytes is a different job with different failure modes to moving rows.
     * `LegacyPosterImporter` does that separately, and an event without its
     * poster is still an event.
     */
    private function importEvents(): void
    {
        // Explicit column list, and _poster is not in it. Selecting * here
        // pulls a megabyte per row through the connection for a column this
        // method has no use for.
        $rows = $this->legacy()->table('events')
            ->select([
                'id', '_organizer', '_title', '_category', '_location', '_timezone',
                '_province', '_venue', '_description', '_start_date', '_start_time',
                '_slug', '_dress_code', '_identity_req', '_status', 'is_featured',
                'created',
            ])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $this->tick('events');

            if ($this->map->find('events', $row->id)) {
                continue;
            }

            $organizationId = $this->map->find('organization_for_account', $row->_organizer);

            if (! $organizationId) {
                // An event whose organizer account is gone. Skipped rather than
                // attached to somebody: an event under the wrong organization
                // is an event whose takings go to the wrong person.
                $this->note("event {$row->id} skipped: organizer {$row->_organizer} not found");

                continue;
            }

            // The source has no currency column. The market is decided by
            // where the event is, and this database is the Canadian one.
            $currency = 'CAD';
            $timezone = LegacyRules::timezoneFor($row->_timezone, $currency);
            $startsAt = LegacyRules::startsAt((string) $row->_start_date, $row->_start_time, $timezone);
            $endsAt = LegacyRules::endsAt($startsAt);

            $inferred = ['ends_at' => 'no end time in source; start + 6h'];

            if (! $row->_timezone) {
                $inferred['timezone'] = "guessed from currency: {$timezone}";
            }

            // `_location` is nullable in the source and `city` is not nullable
            // here. Falling back to the province, then to a placeholder, keeps
            // the event: a listing with a vague location is worth more to the
            // organizer than an event that did not come across at all, and the
            // placeholder is a search term for finding them afterwards.
            $city = trim((string) ($row->_location ?: $row->_province ?: ''));

            if ($city === '') {
                $city = 'Unspecified';
                $inferred['city'] = 'no location in source';
            }

            $status = LegacyRules::eventStatus($row->_status);

            $event = Event::create([
                'organization_id' => $organizationId,
                'slug' => $this->uniqueSlug(
                    Event::class,
                    LegacyRules::slugify((string) ($row->_slug ?: $row->_title), 'event-'.$row->id),
                ),
                'title' => (string) $row->_title,
                'description' => (string) $row->_description,
                'currency' => $currency,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
                'city' => $city,
                'subdivision' => $row->_province ?: null,
                'country' => LegacyRules::countryFor($currency),
                'category' => $row->_category ?: null,
                'dress_code' => $row->_dress_code ?: null,
                'id_required' => (bool) $row->_identity_req,
                'status' => $status,
                'is_featured' => (bool) $row->is_featured,
                'published_at' => $status === 'published' ? ($row->created ?? now()) : null,
                'created_at' => $row->created ?? now(),
            ]);

            $this->map->record('events', $row->id, 'event', $event->id, $inferred);
        }
    }

    /**
     * Ticket types, and the first of the two money units.
     *
     * `event_tickets.ticket_price` is in whole dollars. Everything in this
     * system is in minor units, so it is multiplied — and the multiplication
     * is in LegacyRules where it can be read and tested, because getting it
     * backwards on this table and right on the next would produce a database
     * that reconciles to nothing.
     */
    private function importTicketTypes(): void
    {
        $rows = $this->legacy()->table('event_tickets')->orderBy('id')->get();

        foreach ($rows as $row) {
            $this->tick('event_tickets');

            if ($this->map->find('event_tickets', $row->id)) {
                continue;
            }

            $eventId = $this->map->find('events', $row->_event);

            if (! $eventId) {
                $this->note("ticket type {$row->id} skipped: event {$row->_event} not imported");

                continue;
            }

            $event = Event::find($eventId);

            $type = TicketType::create([
                'event_id' => $eventId,
                'name' => (string) $row->ticket_title,
                'description' => $row->ticket_description ?: null,
                'price_amount' => LegacyRules::ticketTypePrice($row->ticket_price, $event->currency)->amount,
                'admits' => max(1, (int) ($row->admits ?? 1)),
                'quantity_available' => (int) $row->ticket_available,
                'max_per_order' => max(1, (int) $row->max_ticket_per_person),
                // The source holds sale windows as four separate varchars of
                // free text. They are not carried: a window parsed wrongly
                // closes sales on an event that is still selling, and every
                // one of these events has already happened.
                'status' => strtolower((string) $row->_ticket_status) === 'enabled' ? 'on_sale' : 'hidden',
                'created_at' => $row->_ticket_added ?? now(),
            ]);

            $this->map->record('event_tickets', $row->id, 'ticket_type', $type->id);
        }
    }

    /**
     * Orders, and the fee model applied as it should always have been.
     *
     * The source stores `_cost` — what the organizer's tickets came to — and
     * derives everything else from it in generated columns. Those columns are
     * not carried across. They are recomputed, for two reasons.
     *
     * The first is that the stored processing fee is wrong: it adds `0.30` to
     * an amount held in cents, so Stripe's thirty cents was recorded as three
     * tenths of one on all 1,713 paid orders. Importing that figure would
     * import the error and make it permanent.
     *
     * The second is that recomputing is the only way the migrated rows satisfy
     * the same constraints as new ones. An order here has to balance:
     * net_revenue + tax + service_charge = total. The old rows were never held
     * to that and a few of them will not meet it.
     */
    private function importOrders(): void
    {
        $rows = $this->legacy()->table('tickets_sales')->orderBy('sales_id')->get();

        foreach ($rows as $row) {
            $this->tick('tickets_sales');

            if ($this->map->find('tickets_sales', $row->sales_id)) {
                continue;
            }

            $eventId = $this->map->find('events', $row->_event);

            if (! $eventId) {
                $this->note("order {$row->sales_id} skipped: event {$row->_event} not imported");

                continue;
            }

            $event = Event::find($eventId);
            $status = LegacyRules::orderStatus($row->_payment_status);

            // Already in minor units. Not multiplied — see LegacyRules.
            $netRevenue = LegacyRules::orderCost($row->_cost, $event->currency);

            // 8%, as the source's own generated column computed it, and now
            // stated in one place instead of in the schema.
            $serviceCharge = $netRevenue->percentage(
                (int) config('payments.service_charge_bps', 800)
            );

            $total = $netRevenue->plus($serviceCharge);

            $buyerEmail = $this->buyerEmail($row->_guest);

            $order = Order::create([
                'organization_id' => $event->organization_id,
                'event_id' => $eventId,
                'buyer_email' => $buyerEmail,
                'buyer_name' => (string) $row->_guest,
                'currency' => $event->currency,
                'subtotal_amount' => $netRevenue->amount,
                'discount_amount' => 0,
                // The Canadian platform never charged tax. Recording zero is
                // what happened; back-filling HST onto historic orders would
                // invent a liability nobody incurred.
                'tax_amount' => 0,
                'tax_inclusive' => false,
                'net_revenue_amount' => $netRevenue->amount,
                'service_charge_amount' => $serviceCharge->amount,
                'total_amount' => $total->amount,
                'gateway_fee_amount' => $status === 'paid'
                    ? GatewayFee::on($total, 'stripe')->amount
                    : null,
                'gateway' => $status === 'paid' ? 'stripe' : null,
                // `_checkout` holds the Stripe Checkout Session id once one
                // exists, and the string 'AWAITING' before that.
                'gateway_reference' => str_starts_with((string) $row->_checkout, 'cs_')
                    ? (string) $row->_checkout
                    : null,
                'status' => $status,
                'paid_at' => $status === 'paid' ? ($row->_pdate ?? null) : null,
                'created_at' => $row->_pdate ?? now(),
            ]);

            $this->map->record('tickets_sales', $row->sales_id, 'order', $order->id);

            if ($status === 'paid') {
                $this->writeLedger($order);
            }
        }
    }

    /**
     * The ledger for a migrated sale.
     *
     * Written here rather than by running the order back through Fulfiller,
     * which would issue a second set of tickets and email everybody who has
     * ever bought one.
     *
     * No tax entry, because these orders carry none. No service charge entry,
     * because the service charge is not the organizer's money and does not
     * belong in their balance — which is the whole correction this import is
     * carrying forward.
     */
    private function writeLedger(Order $order): void
    {
        LedgerEntry::create([
            'organization_id' => $order->organization_id,
            'event_id' => $order->event_id,
            'order_id' => $order->id,
            'type' => 'sale',
            'amount' => $order->net_revenue_amount,
            'currency' => $order->currency,
            'reason' => "Imported order {$order->reference}",
            'occurred_at' => $order->paid_at ?? $order->created_at,
        ]);
    }

    /**
     * Issued tickets.
     *
     * `is_checkedin` is a boolean and there is nothing else: no scan time, no
     * scanner, no count. So a checked-in ticket arrives checked in with no
     * `checked_in_at`, which is accurate — the old system genuinely did not
     * record when anybody walked through the door.
     */
    private function importTickets(): void
    {
        $rows = $this->legacy()->table('ticket_issued')->orderBy('ticket_id')->get();

        foreach ($rows as $row) {
            $this->tick('ticket_issued');

            if ($this->map->find('ticket_issued', $row->ticket_id)) {
                continue;
            }

            $orderId = $this->map->find('tickets_sales', $row->_sale);
            $typeId = $this->map->find('event_tickets', $row->_type);
            $eventId = $this->map->find('events', $row->event);

            if (! $orderId || ! $eventId) {
                $this->note("ticket {$row->ticket_id} skipped: order or event not imported");

                continue;
            }

            $order = Order::find($orderId);
            $type = $typeId ? TicketType::find($typeId) : null;

            $ticket = Ticket::create([
                // The old code is kept. It is printed on tickets people are
                // holding and scanned at doors — reissuing would invalidate
                // every ticket already sold for an event that has not happened.
                'code' => (string) $row->ticket,
                'event_id' => $eventId,
                'ticket_type_id' => $typeId,
                'order_id' => $orderId,
                'owner_email' => $order->buyer_email,
                'holder_name' => $row->_custom_name ?: $order->buyer_name,
                'status' => $row->is_checkedin ? 'checked_in' : 'valid',
                'admits' => $type?->admits ?? 1,
                'admitted_count' => $row->is_checkedin ? ($type?->admits ?? 1) : 0,
                'created_at' => $row->_date ?? now(),
            ]);

            $this->map->record('ticket_issued', $row->ticket_id, 'ticket', $ticket->id, [
                'checked_in_at' => 'not recorded by the source',
            ]);
        }
    }

    /**
     * Settlements: money already paid out.
     *
     * `settlements.amount` is a `double` in the source — floating point, for
     * money — so it is rounded to minor units on the way in rather than cast
     * and hoped over.
     */
    private function importSettlements(): void
    {
        $rows = $this->legacy()->table('settlements')->orderBy('id')->get();

        foreach ($rows as $row) {
            $this->tick('settlements');

            if ($this->map->find('settlements', $row->id)) {
                continue;
            }

            $eventId = $this->map->find('events', $row->event);
            $organizationId = $this->map->find('organization_for_account', $row->organizer);

            if (! $organizationId) {
                $this->note("settlement {$row->id} skipped: organizer {$row->organizer} not found");

                continue;
            }

            $amount = (int) round(((float) $row->amount) * 100);

            $entry = LedgerEntry::create([
                'organization_id' => $organizationId,
                'event_id' => $eventId,
                'type' => 'settlement',
                'amount' => -$amount,
                'currency' => 'CAD',
                'reason' => $row->note ?: 'Imported settlement',
                'occurred_at' => $row->date ?? now(),
            ]);

            $this->map->record('settlements', $row->id, 'ledger_entry', $entry->id, [
                'amount' => 'source held this as a floating point double',
            ]);
        }
    }

    /**
     * The buyer's address, which the source does not reliably hold.
     *
     * `tickets_sales._guest` is a varchar that is sometimes an email and
     * sometimes a name. Where there is no address, a placeholder on an
     * unroutable domain is used rather than a blank: the column is required,
     * and an address that cannot be delivered to is safer than one that might
     * belong to somebody else.
     */
    private function buyerEmail(?string $guest): string
    {
        $guest = trim((string) $guest);

        if (filter_var($guest, FILTER_VALIDATE_EMAIL)) {
            return strtolower($guest);
        }

        return 'unknown-'.Str::random(12).'@imported.invalid';
    }

    /**
     * A slug nothing else is using.
     *
     * The old events table has duplicate titles across years — the same club
     * night every month — and `_slug` is frequently null.
     *
     * @param  class-string  $model
     */
    private function uniqueSlug(string $model, string $base): string
    {
        $slug = $base;
        $n = 2;

        while ($model::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function tick(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        if ($this->progress) {
            ($this->progress)($key, $this->counts[$key]);
        }
    }

    private function note(string $message): void
    {
        $this->notes[] = $message;
    }
}
