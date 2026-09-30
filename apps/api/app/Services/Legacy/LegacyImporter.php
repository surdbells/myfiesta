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
use App\Models\Venue;
use App\Services\Events\EventReviews;
use App\Services\Payments\GatewayFee;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
 * Three properties matter more than speed here:
 *
 *   Re-runnable. Every row is looked up in legacy_map before it is created.
 *   This will be interrupted — it is a quarter of a gigabyte of poster blobs
 *   across a network — and the recovery has to be `run it again`.
 *
 *   Whole rows or nothing. Each source row — an order with its lines, its
 *   ledger entry and its legacy_map row; an account with its organization —
 *   commits in one short transaction. A row that fails part-way leaves
 *   nothing behind, is written to legacy_import_failures with the reason,
 *   and the run moves on to the next one. The map row commits with the rest,
 *   so "done" in the map always means done in the tables, and the next run
 *   retries exactly the rows that are missing.
 *
 *   Honest about what it invented. Events have no end time in the source and
 *   most have no timezone; both are inferred, both are recorded as inferred,
 *   and the run reports the count at the end rather than leaving somebody to
 *   discover it from a support ticket.
 *
 * And, because a cutover with a parallel run imports the same database again
 * and again while the old app is still selling, honest about what moved since:
 * a row that came across is never changed here, but a run says which of them
 * the old app has changed or deleted since (legacy_map.source_hash), and
 * leaves a checkout that may still be paid for a later run. With $dryRun it
 * reads everything, writes nothing, and says what a run would do.
 *
 * What is deliberately not imported: the `transactions` table (19 rows of
 * hand-written narration that is not a ledger), `blogs`, `newsletter`,
 * `featured`, `notes`, and the various lookup tables whose values are already
 * denormalised into the rows that reference them.
 */
class LegacyImporter
{
    /**
     * The columns the import reads from each source table, and so the ones a
     * row's fingerprint covers (LegacyRules::fingerprint). A change to any
     * other column changes nothing that came across.
     */
    private const READ = [
        'user_accounts' => [
            'id', 'first_name', 'last_name', 'email_address', 'phone_number', 'password', '_registered',
        ],
        'events' => [
            'id', '_organizer', '_title', '_category', '_location', '_timezone',
            '_province', '_venue', '_description', '_start_date', '_start_time',
            '_slug', '_dress_code', '_identity_req', '_status', 'is_featured',
            'created',
        ],
        'event_tickets' => [
            'id', '_event', 'ticket_title', 'ticket_description', 'ticket_price', 'admits',
            'ticket_available', 'max_ticket_per_person', '_ticket_status', '_ticket_added',
        ],
        'tickets_sales' => [
            'sales_id', '_event', '_ticket', '_guest', '_cost', '_checkout', '_payment_status', '_pdate',
        ],
        'ticket_issued' => [
            'ticket_id', 'event', 'ticket', '_type', '_sale', '_custom_name', '_date', 'is_checkedin',
        ],
        'settlements' => ['id', 'event', 'organizer', 'amount', 'note', 'date'],
    ];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * What became of each source row this run, per table: already here, new,
     * tried again, failed, waiting for a parent, or left for a later run.
     *
     * @var array<string, array<string, int>>
     */
    private array $plan = [];

    /**
     * Rows that came across on an earlier run and say something else now.
     *
     * @var array<string, list<string>>
     */
    private array $changed = [];

    /**
     * Every id read from each source table this run, to find the ones that
     * came across and are no longer there.
     *
     * @var array<string, array<string, true>>
     */
    private array $seen = [];

    /**
     * Sales left for a later run because their checkout may still be paid.
     *
     * @var list<string>
     */
    private array $deferred = [];

    private readonly CarbonImmutable $now;

    /** @var list<string> */
    private array $notes = [];

    /** @var list<array{table: string, id: string, reason: string}> */
    private array $failures = [];

    /**
     * What the row in progress said and made, kept only if it commits.
     *
     * A note about a dropped basket line on an order that then rolled back is
     * a note about something that does not exist; a count of venues made by
     * it counts a venue that was never made; and a logo written for an
     * organization that rolled back is a file nothing will ever point at.
     */
    private bool $inUnit = false;

    /** @var list<string> */
    private array $pendingNotes = [];

    /** @var list<string> */
    private array $pendingTicks = [];

    /** @var list<string> */
    private array $pendingFiles = [];

    /**
     * Ids the old database holds, per table, read once when first asked.
     *
     * @var array<string, array<string, true>>
     */
    private array $sourceIds = [];

    public function __construct(
        private readonly LegacyMap $map,
        private readonly ?\Closure $progress = null,
        private readonly bool $dryRun = false,
        // The old app has stopped taking orders, so a checkout it left open
        // will never be settled there: brought now as it stands, not left.
        private readonly bool $frozen = false,
    ) {
        $this->now = CarbonImmutable::now();
    }

    private function legacy(): ConnectionInterface
    {
        return DB::connection('legacy');
    }

    /**
     * @return array{
     *     counts: array<string, int>,
     *     notes: list<string>,
     *     failures: list<array{table: string, id: string, reason: string}>,
     *     plan: array<string, array<string, int>>,
     *     changed: array<string, list<string>>,
     *     gone: array<string, list<string>>,
     *     deferred: list<string>,
     *     orders: list<array{legacy_id: string, reference: string, here: string, there: string, advice: string}>,
     * }
     */
    public function run(): array
    {
        // Order is dependency order, and each source row commits on its own
        // (see unit()). One transaction around the whole run would hold locks
        // for as long as the import takes and lose everything to a single bad
        // row two hours in.
        $this->importOrganizers();
        $this->importEvents();
        $this->importTicketTypes();

        if (! $this->dryRun) {
            $this->approveImportedOnSale();
        }

        $this->importOrders();
        $this->importTickets();
        $this->importSettlements();

        // Before the plan is handed over: it counts them too.
        $gone = $this->gone();

        return [
            'counts' => $this->counts,
            'notes' => $this->notes,
            'failures' => $this->failures,
            'plan' => $this->plan,
            'changed' => $this->changed,
            'gone' => $gone,
            'deferred' => $this->deferred,
            'orders' => $this->changedOrders(),
        ];
    }

    /**
     * Whether a source row came across on an earlier run, counted either way.
     *
     * One that did is passed over, and never changed here: a ledger entry, a
     * refund or a ticket may hang off it by now. But it is compared with what
     * it said when it came across, and listed if the old app has changed it
     * since. A row that came across before fingerprints were kept has none to
     * compare with, and is not guessed at.
     */
    private function across(string $table, int|string $id, object $row): bool
    {
        $this->tick($table);
        $this->seen[$table][(string) $id] = true;

        if ($this->map->find($table, $id) === null) {
            return false;
        }

        $this->outcome($table, 'across');

        $then = $this->map->fingerprint($table, $id);

        if ($then !== null && $then !== $this->fingerprintOf($table, $row)) {
            $this->outcome($table, 'changed');
            $this->changed[$table][] = (string) $id;
        }

        return true;
    }

    /**
     * A row still to come across: brought now, or in a dry run, counted.
     *
     * A dry run remembers it in memory as if it had come, so the rows that
     * hang off it are counted the way a real run would bring them rather than
     * as waiting. $alsoMakes is a row derived from it that later rows look up
     * (an account's organization). Whether a row would fail cannot be known
     * without writing it; a dry run counts it as tried.
     *
     * A real run counts it once it has committed. One that fails is counted
     * as failed by unit() and nowhere else, so each row read is in one column
     * of the summary, and `new` is what did come across.
     */
    private function bring(string $table, int|string $id, \Closure $work, ?string $alsoMakes = null): void
    {
        // Asked first: coming across clears the failure it is asking about.
        $outcome = $this->map->hasFailed($table, $id) ? 'retried' : 'new';

        if (! $this->dryRun) {
            if ($this->unit($table, $id, $work)) {
                $this->outcome($table, $outcome);
            }

            return;
        }

        $this->outcome($table, $outcome);
        $this->map->pretend($table, $id);

        if ($alsoMakes !== null) {
            $this->map->pretend($alsoMakes, $id);
        }
    }

    /**
     * A row passed over because something it needs is not here.
     */
    private function waiting(string $table, string $note): void
    {
        $this->outcome($table, 'waiting');
        $this->note($note);
    }

    private function outcome(string $table, string $what): void
    {
        $this->plan[$table][$what] = ($this->plan[$table][$what] ?? 0) + 1;
    }

    private function fingerprintOf(string $table, object $row): string
    {
        return LegacyRules::fingerprint($row, self::READ[$table]);
    }

    /**
     * Rows that came across and are no longer in the old database, per table.
     *
     * Kept here: an order the old app deleted after it came across may still
     * be somebody's ticket, and a person decides that, not a run.
     *
     * @return array<string, list<string>>
     */
    private function gone(): array
    {
        $gone = [];

        foreach (array_keys(self::READ) as $table) {
            $ids = array_values(array_diff(
                $this->map->idsFrom($table),
                array_map('strval', array_keys($this->seen[$table] ?? [])),
            ));

            if ($ids !== []) {
                $gone[$table] = $ids;
                $this->plan[$table]['gone'] = count($ids);
            }
        }

        return $gone;
    }

    /**
     * The orders the old app changed after they came across, each with what
     * this database says, what the old one says now, and what to do.
     *
     * The old statuses are read again here, in one query, rather than kept for
     * every row read on the chance that one of them changed.
     *
     * @return list<array{legacy_id: string, reference: string, here: string, there: string, advice: string}>
     */
    private function changedOrders(): array
    {
        $legacyIds = $this->changed['tickets_sales'] ?? [];

        if ($legacyIds === []) {
            return [];
        }

        $there = $this->legacy()->table('tickets_sales')
            ->whereIn('sales_id', $legacyIds)
            ->pluck('_payment_status', 'sales_id')
            ->mapWithKeys(fn ($status, $id) => [(string) $id => LegacyRules::orderStatus($status)]);

        $orders = Order::query()
            ->whereIn('id', array_map(fn (string $id) => $this->map->find('tickets_sales', $id), $legacyIds))
            ->get(['id', 'reference', 'status'])
            ->keyBy('id');

        $rows = [];

        foreach ($legacyIds as $legacyId) {
            $order = $orders[$this->map->find('tickets_sales', $legacyId)] ?? null;
            $here = (string) ($order->status ?? '');
            $now = (string) ($there[$legacyId] ?? '');

            $rows[] = [
                'legacy_id' => $legacyId,
                'reference' => (string) ($order->reference ?? ''),
                'here' => $here,
                'there' => $now,
                'advice' => LegacyRules::changedOrderAdvice($here, $now),
            ];
        }

        return $rows;
    }

    /**
     * One source row, all of it or none of it.
     *
     * The work runs in one short transaction together with the row's
     * legacy_map entry (LegacyMap::atomically). If anything in it throws — bad
     * data, a constraint, the connection going — the row is rolled back,
     * recorded in legacy_import_failures under its source table and id, and
     * the run moves on. Rows that depend on it are skipped as missing, or fail
     * in turn waiting for it (waitFor), and are never written without it; all
     * of them are tried again on the next run.
     *
     * True when the row committed, false when it was rolled back.
     */
    private function unit(string $sourceTable, int|string $sourceId, \Closure $work): bool
    {
        $this->inUnit = true;
        $this->pendingNotes = $this->pendingTicks = $this->pendingFiles = [];

        try {
            $this->map->atomically(function () use ($work, $sourceTable, $sourceId): void {
                $work();

                // In the same commit as the row. Marked afterwards, a run that
                // died in between left a failure outstanding for a row the map
                // called done — which no later run would ever try again, and
                // so none would ever clear.
                $this->map->resolved($sourceTable, $sourceId);
            });
        } catch (\Throwable $e) {
            // Files are outside the transaction, so they are taken back by
            // hand: a failed organization does not leave its logo behind.
            if ($this->pendingFiles !== []) {
                Storage::disk('public')->delete($this->pendingFiles);
            }

            $this->outcome($sourceTable, 'failed');

            $reason = LegacyRules::failureReason($e);

            $this->map->failed($sourceTable, $sourceId, $reason);
            $this->failures[] = ['table' => $sourceTable, 'id' => (string) $sourceId, 'reason' => $reason];

            return false;
        } finally {
            $this->inUnit = false;
        }

        array_push($this->notes, ...$this->pendingNotes);

        foreach ($this->pendingTicks as $key) {
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }

        return true;
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
            if ($this->across('user_accounts', $row->id, $row)) {
                continue;
            }

            // The account and its organization are one unit. Committed apart,
            // an account whose organization failed was marked done, the next
            // run skipped it, and every event it ran was skipped after it for
            // want of an organizer.
            $this->bring('user_accounts', $row->id, fn () => $this->importOrganizer($row), 'organization_for_account');
        }
    }

    private function importOrganizer(object $row): void
    {
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

        // The brand, which is what buyers actually saw. Without it an
        // organization ends up named after the person rather than the
        // promoter — "Ada Okoro" where every poster said "Lagos Nights".
        $brand = $this->brandFor($row->id);

        $organizationName = $brand?->brand_name ?: $name;

        if ($organizationName === '') {
            $organizationName = (string) $row->email_address;
        }

        $organization = Organization::create([
            'name' => $organizationName,
            'slug' => $this->uniqueSlug(
                Organization::class,
                LegacyRules::slugify($organizationName, 'organizer-'.$row->id),
            ),
            'description' => $brand?->brand_description ?: null,
            'contact_email' => strtolower(trim(
                $brand?->brand_email_address ?: (string) $row->email_address
            )),
            'contact_phone' => $brand?->brand_number ?: ($row->phone_number ?: null),
            'instagram' => $brand?->brand_instagram ?: null,
            'facebook' => $brand?->brand_facebook ?: null,
            'x_handle' => $brand?->brand_twitter ?: null,
            'created_at' => $row->_registered ?? now(),
        ]);

        $this->importLogo($organization, $brand?->extra_id);

        $organization->members()->attach($user->id, [
            'role' => 'owner',
            'accepted_at' => $row->_registered ?? now(),
        ]);

        $this->map->record('user_accounts', $row->id, 'user', $user->id, $inferred, $this->fingerprintOf('user_accounts', $row));

        // Keyed by the same legacy id: events name their organizer by the
        // account id, and this is what turns that into an organization.
        $this->map->record('organization_for_account', $row->id, 'organization', $organization->id);
        $this->tick('organizations');
    }

    /**
     * The venue, promoted from a string to a row.
     *
     * `events._venue` is a varchar the organizer typed. The same room appears
     * on every event they run there, so one venue is created per organization
     * per name and reused — which is the point of the table, and turns "where
     * is this?" from a string comparison into a relationship.
     *
     * Matched case-insensitively on a trimmed name. "The Room" and "the room "
     * are the same place and an organizer typing it monthly will produce both.
     *
     * Made inside the event's unit, so a venue whose event rolls back goes
     * with it and the map forgets it too.
     */
    private function venueFor(
        string $organizationId,
        ?string $name,
        string $city,
        ?string $subdivision,
        string $timezone,
    ): ?string {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $key = strtolower($name);
        $cached = $this->map->find('venue_for_org', $organizationId.':'.$key);

        if ($cached) {
            return $cached;
        }

        $venue = Venue::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'city' => $city,
            'subdivision' => $subdivision ?: null,
            'country' => 'CA',
            'timezone' => $timezone,
        ]);

        $this->map->record('venue_for_org', $organizationId.':'.$key, 'venue', $venue->id);
        $this->tick('venues');

        return $venue->id;
    }

    /**
     * The organizer's brand, out of `extra_data`.
     *
     * Only the public half of that table. It also holds bank and Interac
     * payout details and a set of identity documents — legal name, date of
     * birth, government ID number and a photograph of the document — and none
     * of that is carried across. Organizers re-verify, and payout details are
     * confirmed as part of that, so importing bank details that cannot be paid
     * out until verification completes would move the liability without
     * bringing forward the moment anybody can be paid.
     */
    private function brandFor(int|string $accountId): ?object
    {
        return $this->legacy()->table('extra_data')
            ->select([
                'extra_id', 'brand_name', 'brand_description', 'brand_number',
                'brand_email_address', 'brand_twitter', 'brand_facebook', 'brand_instagram',
            ])
            ->where('user_account', (string) $accountId)
            ->first();
    }

    /**
     * The organization's logo, which is a data URI in a longtext column.
     */
    private function importLogo(Organization $organization, int|string|null $extraId): void
    {
        if ($extraId === null) {
            return;
        }

        $row = $this->legacy()->table('extra_logo')->where('extra', (string) $extraId)->first();

        $image = LegacyRules::decodeImage($row->logo ?? null);

        if ($image === null) {
            return;
        }

        $path = 'organizations/'.$organization->id.'/logo-'.Str::random(8)
            .'.'.LegacyRules::extensionFor($image['mime']);

        Storage::disk('public')->put($path, $image['bytes']);

        // Remembered before anything else can fail, so a rollback of the
        // organization takes the file with it.
        $this->pendingFiles[] = $path;

        $organization->update(['logo_path' => $path]);

        $this->tick('logos');
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
            ->select(self::READ['events'])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($this->across('events', $row->id, $row)) {
                continue;
            }

            $organizationId = $this->map->find('organization_for_account', $row->_organizer);

            if (! $organizationId) {
                // An event whose organizer account is gone. Skipped rather than
                // attached to somebody: an event under the wrong organization
                // is an event whose takings go to the wrong person.
                $this->waiting('events', "event {$row->id} skipped: organizer {$row->_organizer} not found");

                continue;
            }

            $this->bring('events', $row->id, fn () => $this->importEvent($row, $organizationId));
        }
    }

    private function importEvent(object $row, string $organizationId): void
    {
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

        $wantedSlug = LegacyRules::slugify((string) ($row->_slug ?: $row->_title), 'event-'.$row->id);
        $slug = $this->uniqueSlug(Event::class, $wantedSlug);

        if (in_array($wantedSlug, Event::RESERVED_SLUGS, true)) {
            $inferred['slug'] = "'{$wantedSlug}' is one of this site's own pages; the event is at '{$slug}'";
        }

        $event = Event::create([
            'organization_id' => $organizationId,
            'venue_id' => $this->venueFor($organizationId, $row->_venue, $city, $row->_province, $timezone),
            'slug' => $slug,
            'title' => (string) $row->_title,
            'description' => (string) $row->_description,
            'currency' => $currency,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
            'city' => $city,
            'subdivision' => $row->_province ?: null,
            'country' => LegacyRules::countryFor($currency),
            'category' => LegacyRules::category($row->_category),
            'dress_code' => $row->_dress_code ?: null,
            'id_required' => (bool) $row->_identity_req,
            'status' => $status,
            'is_featured' => (bool) $row->is_featured,
            'published_at' => $status === 'published' ? ($row->created ?? now()) : null,
            'created_at' => $row->created ?? now(),
        ]);

        $this->map->record('events', $row->id, 'event', $event->id, $inferred, $this->fingerprintOf('events', $row));
    }

    /**
     * Imported events that were on sale, approved as they came across.
     *
     * They were on sale on the previous platform, and taking every one of them
     * off to be looked at again would punish organizers for a rule that did
     * not exist when they published. Recorded after their ticket types, so
     * the fingerprint is of the listing a buyer sees; a poster that arrives
     * later (LegacyPosterImporter) is a change since, and only matters if the
     * organizer takes the event off sale and puts it back.
     *
     * Only events the map says came from the old database, and only once:
     * a re-run finds them approved and leaves them alone.
     */
    private function approveImportedOnSale(): void
    {
        $reviews = app(EventReviews::class);

        Event::query()
            ->where('status', 'published')
            ->whereNull('approved_at')
            ->whereIn('id', DB::table('legacy_map')->where('source_table', 'events')->select('target_id'))
            ->chunkById(200, function ($events) use ($reviews) {
                foreach ($events as $event) {
                    $reviews->recordApproval($event, null, EventReviews::VIA_IMPORTED);
                }
            });
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
            if ($this->across('event_tickets', $row->id, $row)) {
                continue;
            }

            $eventId = $this->map->find('events', $row->_event);

            if (! $eventId) {
                $this->waiting('event_tickets', "ticket type {$row->id} skipped: event {$row->_event} not imported");

                continue;
            }

            $this->bring('event_tickets', $row->id, fn () => $this->importTicketType($row, $eventId));
        }
    }

    private function importTicketType(object $row, string $eventId): void
    {
        $event = Event::findOrFail($eventId);

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

        $this->map->record('event_tickets', $row->id, 'ticket_type', $type->id, [], $this->fingerprintOf('event_tickets', $row));
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
     *
     * Every money figure written here is a claim about what Stripe collected,
     * and none of it is checked against Stripe at import time. That is
     * `legacy:reconcile`, run after this.
     */
    private function importOrders(): void
    {
        $rows = $this->legacy()->table('tickets_sales')->orderBy('sales_id')->get();

        foreach ($rows as $row) {
            if ($this->across('tickets_sales', $row->sales_id, $row)) {
                continue;
            }

            // A basket somebody may be paying for right now, in the old app.
            // Brought across now it arrives cancelled, and the payment a
            // minute later reaches only the old app. A later run brings it
            // once the old app knows (LegacyRules::checkoutMayStillBePaid).
            // Not once it is frozen: then it never will, and waiting only
            // keeps the sale out of legacy:reconcile, which is what finds a
            // payment that landed after the freeze (docs/CUTOVER.md).
            if (! $this->frozen && LegacyRules::checkoutMayStillBePaid($row->_payment_status, $row->_pdate, $this->now)) {
                $this->outcome('tickets_sales', 'deferred');
                $this->deferred[] = (string) $row->sales_id;

                continue;
            }

            $eventId = $this->map->find('events', $row->_event);

            if (! $eventId) {
                $this->waiting('tickets_sales', "order {$row->sales_id} skipped: event {$row->_event} not imported");

                continue;
            }

            // The order, its basket, its ledger entry and its map row are one
            // unit. This is the row the unit exists for: an order written
            // without its lines cannot be refunded, one written without its
            // ledger entry is money the organizer is never credited, and
            // either of them marked done in the map stays that way.
            $this->bring('tickets_sales', $row->sales_id, fn () => $this->importOrder($row, $eventId));
        }
    }

    private function importOrder(object $row, string $eventId): void
    {
        $event = Event::findOrFail($eventId);
        $status = LegacyRules::orderStatus($row->_payment_status);

        // Already in minor units. Not multiplied — see LegacyRules.
        $netRevenue = LegacyRules::orderCost($row->_cost, $event->currency);

        // 8%, as the source's own generated column computed it, and now
        // stated in one place instead of in the schema.
        $serviceCharge = $netRevenue->percentage(
            (int) config('payments.service_charge_bps', 800)
        );

        $total = $netRevenue->plus($serviceCharge);

        // `First|Last|email`, on every row in that table. Reading it as an
        // address and falling back to a placeholder threw away the email
        // for all 2,694 of them, which is where every ticket was sent.
        $buyer = LegacyRules::parseBuyer($row->_guest);

        $buyerEmail = $buyer['email']
            ?? 'unknown-'.Str::random(12).'@imported.invalid';

        $order = Order::create([
            'organization_id' => $event->organization_id,
            'event_id' => $eventId,
            'buyer_email' => $buyerEmail,
            'buyer_name' => $buyer['name'] !== '' ? $buyer['name'] : $buyerEmail,
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

        $this->importOrderLines($order, $row, $netRevenue->amount);

        if ($status === 'paid') {
            $this->writeLedger($order);
        }

        // Last, and in the same transaction as everything above it: the map
        // says done only once there is nothing left to do.
        $this->map->record('tickets_sales', $row->sales_id, 'order', $order->id, [], $this->fingerprintOf('tickets_sales', $row));
    }

    /**
     * What was in the basket.
     *
     * Not decoration. Refunds allocate by order line weight — a table of ten
     * refunds a table's worth and a single ticket refunds a single ticket's —
     * so an order with no lines has every weight at zero and cannot be
     * refunded correctly at all. It is also the only record of what anybody
     * bought, which is the first thing an organizer looks for.
     *
     * The basket is in `_ticket`, in two formats from two eras, and every one
     * of the 2,694 rows reconciles against `_cost` under one of them.
     */
    private function importOrderLines(Order $order, object $row, int $expected): void
    {
        $lines = LegacyRules::parseBasket($row->_ticket ?? null, $expected);

        if ($lines === []) {
            $this->note("order {$row->sales_id} has no readable basket");

            return;
        }

        $written = 0;

        foreach ($lines as $line) {
            $typeId = $this->map->find('event_tickets', $line['legacy_type_id']);

            if (! $typeId) {
                // Still in the old database: it failed, and the order waits
                // for it rather than committing without its basket.
                $this->waitFor('event_tickets', $line['legacy_type_id'], 'ticket type');

                // The ticket type was deleted before the dump. The line cannot
                // be written — order_lines requires the type — and the order
                // is still worth keeping without it.
                $this->note(
                    "order {$row->sales_id}: ticket type {$line['legacy_type_id']} is gone, line dropped"
                );

                continue;
            }

            $type = TicketType::find($typeId);
            $quantity = max(1, $line['quantity']);

            OrderLine::create([
                'order_id' => $order->id,
                'ticket_type_id' => $typeId,
                // Snapshotted, as it is for a live order: the type's price can
                // change afterwards and this has to stay what was charged.
                'name' => $type?->name ?? 'Ticket',
                'unit_price_amount' => intdiv($line['line_total'], $quantity),
                'quantity' => $quantity,
                'line_total_amount' => $line['line_total'],
            ]);

            $written++;
        }

        if ($written === 0) {
            $this->note("order {$row->sales_id} imported with no lines at all");
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
            if ($this->across('ticket_issued', $row->ticket_id, $row)) {
                continue;
            }

            $orderId = $this->map->find('tickets_sales', $row->_sale);
            $eventId = $this->map->find('events', $row->event);

            if (! $orderId || ! $eventId) {
                $this->waiting('ticket_issued', "ticket {$row->ticket_id} skipped: order or event not imported");

                continue;
            }

            $this->bring('ticket_issued', $row->ticket_id, fn () => $this->importTicket($row, $orderId, $eventId));
        }
    }

    private function importTicket(object $row, string $orderId, string $eventId): void
    {
        $typeId = $this->map->find('event_tickets', $row->_type);

        if (! $typeId) {
            $this->waitFor('event_tickets', $row->_type, 'ticket type');
        }

        $order = Order::findOrFail($orderId);
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
        ], $this->fingerprintOf('ticket_issued', $row));
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
            if ($this->across('settlements', $row->id, $row)) {
                continue;
            }

            $organizationId = $this->map->find('organization_for_account', $row->organizer);

            if (! $organizationId) {
                $this->waiting('settlements', "settlement {$row->id} skipped: organizer {$row->organizer} not found");

                continue;
            }

            $this->bring('settlements', $row->id, fn () => $this->importSettlement($row, $organizationId));
        }
    }

    private function importSettlement(object $row, string $organizationId): void
    {
        $amount = (int) round(((float) $row->amount) * 100);

        $eventId = $this->map->find('events', $row->event);

        if (! $eventId) {
            // Filed under no event, a settlement for an event that came across
            // on a later run stayed that way: the event's takings read as
            // never paid out, and the payout as belonging to nothing.
            $this->waitFor('events', $row->event, 'event');

            if ((string) $row->event !== '') {
                $this->note("settlement {$row->id}: event {$row->event} is not in the old database, kept without it");
            }
        }

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
        ], $this->fingerprintOf('settlements', $row));
    }

    /**
     * A parent that is still in the old database and has not come across yet.
     *
     * It failed, or it is waiting on a parent of its own, and either way a
     * later run brings it. Written around instead — a basket line dropped, a
     * settlement filed under no event — the row would commit without it, the
     * map would call it done, and the run that brought the parent would pass
     * it by: a paid order with no lines for good, and nothing left outstanding
     * to say so. So the whole row fails here, is listed with the others, and
     * is tried again once its parent is across.
     *
     * A parent the old database does not hold at all is gone, and the caller
     * keeps the row without it as before.
     *
     * @throws \RuntimeException when the parent is still to come
     */
    private function waitFor(string $table, int|string|null $legacyId, string $what): void
    {
        if ($legacyId !== null && $this->inSource($table, $legacyId)) {
            throw new \RuntimeException("waiting for {$what} {$legacyId}, which has not come across yet");
        }
    }

    /**
     * Whether the old database holds a row with this id.
     *
     * Every id at once, the first time a table is asked about: the tables
     * asked about are small, and a question per basket line is not.
     */
    private function inSource(string $table, int|string $legacyId): bool
    {
        $this->sourceIds[$table] ??= $this->legacy()->table($table)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();

        return isset($this->sourceIds[$table][(string) $legacyId]);
    }

    /**
     * A slug nothing else is using, and one the site can reach.
     *
     * The old events table has duplicate titles across years — the same club
     * night every month — and `_slug` is frequently null.
     *
     * An event also stays off the words the public site answers at its root
     * (Event::RESERVED_SLUGS), the same way a new one does: an imported night
     * called "Events" or "Refunds" was given a link the site's own page
     * answers, and nobody could reach it. A reserved word takes a suffix like
     * any collision. Organizations live under /o/, where nothing else is, so
     * theirs only have to be unique.
     *
     * @param  class-string  $model
     */
    private function uniqueSlug(string $model, string $base): string
    {
        $taken = $model === Event::class
            ? fn (string $slug): bool => Event::slugIsTaken($slug, withTrashed: true)
            : fn (string $slug): bool => $model::withTrashed()->where('slug', $slug)->exists();

        $slug = $base;
        $n = 2;

        while ($taken($slug)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Counted now for a row read, or on commit for something a unit made.
     */
    private function tick(string $key): void
    {
        if ($this->inUnit) {
            $this->pendingTicks[] = $key;

            return;
        }

        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        if ($this->progress) {
            ($this->progress)($key, $this->counts[$key]);
        }
    }

    private function note(string $message): void
    {
        if ($this->inUnit) {
            $this->pendingNotes[] = $message;

            return;
        }

        $this->notes[] = $message;
    }
}
