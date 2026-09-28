<?php

namespace App\Services\Audit;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Whether somebody was myFiesta staff when they did something, and as what.
 *
 * Read from the trail itself. Every staff role given, changed or taken away
 * is an audit entry about that person (StaffAccess, and User when a new
 * address takes the role with it), so whether somebody held a role at any
 * moment is what the latest of those entries up to then says — and before
 * the first, the opposite of what it did: a grant means they held none.
 *
 * Their role now says nothing about then: an organizer made staff today did
 * not ask for last month's payout as staff, and somebody who has left did
 * their work here as staff all the same. The one person this cannot answer
 * for is somebody given a role before roles were recorded, with no entry at
 * all; for them the role now is the only answer there is.
 *
 * Which role is read from the same entries; the question the audit log's
 * filter asks — staff or not — is answered from the entries alone, here and
 * in SQL alike (heldSql), so the label and the filter never disagree. Entries
 * are to the second, so something done in the same second as a change counts
 * as done after it.
 */
final class StaffRoles
{
    /** The entries that change a staff role, each about the person whose role it is. */
    public const CHANGES = ['staff.granted', 'staff.role_changed', 'staff.revoked'];

    /** @var array<string, list<array{at: CarbonImmutable, action: string, from: ?PlatformRole, to: ?PlatformRole}>> */
    private array $changes = [];

    /** One for the request, so a page of entries reads each person's history once. */
    public static function shared(): self
    {
        return once(fn (): self => new self);
    }

    /**
     * "myFiesta staff · Administrator" for somebody who held a role at that
     * moment, or null for somebody who held none.
     */
    public function describe(?User $user, ?CarbonInterface $when): ?string
    {
        [$held, $role] = $this->position($user, $when);

        if (! $held) {
            return null;
        }

        return 'myFiesta staff'.($role !== null ? ' · '.$role->label() : '');
    }

    /**
     * Whether they held a role then, and which.
     *
     * @return array{0: bool, 1: ?PlatformRole}
     */
    private function position(?User $user, ?CarbonInterface $when): array
    {
        if ($user === null) {
            return [false, null];
        }

        $this->load([$user->getKey()]);

        $changes = $this->changes[$user->getKey()];
        $now = $user->platform_role;

        if ($changes === [] || $when === null) {
            return [$now !== null, $now];
        }

        $latest = null;

        foreach ($changes as $change) {
            if ($change['at']->greaterThan($when)) {
                break;
            }

            $latest = $change;
        }

        if ($latest !== null) {
            $held = $latest['action'] !== 'staff.revoked';

            return [$held, $held ? ($latest['to'] ?? $now) : null];
        }

        $first = $changes[0];
        $held = $first['action'] !== 'staff.granted';

        return [$held, $held ? ($first['from'] ?? $now) : null];
    }

    /**
     * Read the history of several people at once, for a page of entries
     * that would otherwise ask once a row.
     *
     * @param  list<string|null>  $userIds
     */
    public function load(array $userIds): void
    {
        $missing = array_values(array_unique(array_filter(
            $userIds,
            fn (?string $id) => $id !== null && ! array_key_exists($id, $this->changes),
        )));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $id) {
            $this->changes[$id] = [];
        }

        AuditLog::query()
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $missing)
            ->whereIn('action', self::CHANGES)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['subject_id', 'action', 'metadata', 'created_at'])
            ->each(function (AuditLog $entry): void {
                $metadata = is_array($entry->metadata) ? $entry->metadata : [];
                $role = fn (string $key): ?PlatformRole => is_string($metadata[$key] ?? null) ? PlatformRole::tryFrom($metadata[$key]) : null;

                [$from, $to] = match ($entry->action) {
                    'staff.granted' => [null, $role('role')],
                    'staff.role_changed' => [$role('from'), $role('to')],
                    default => [$role('role'), null],
                };

                $this->changes[(string) $entry->subject_id][] = [
                    'at' => CarbonImmutable::instance($entry->created_at),
                    'action' => $entry->action,
                    'from' => $from,
                    'to' => $to,
                ];
            });
    }

    /**
     * The same question in SQL, for a filter: whether the person in
     * `$personColumn` held a staff role at `$atColumn`.
     *
     * The latest change up to that moment says so, if there is one; failing
     * that, the first change after it (a grant means they held none before);
     * failing both, whether they hold one now. Each is an index lookup on the
     * trail's subject index, and only for rows by somebody who has ever been
     * staff.
     *
     * @return array{0: string, 1: list<string>} the expression, and its bindings
     */
    public static function heldSql(string $personColumn, string $atColumn): array
    {
        $changes = implode(', ', array_fill(0, count(self::CHANGES), '?'));
        $about = "h.subject_type = ? and h.subject_id = {$personColumn} and h.action in ({$changes})";

        $sql = "({$personColumn} is not null and {$personColumn} in (select u.id from users u where u.platform_role is not null"
            ." union select h.subject_id from audit_logs h where h.subject_type = ? and h.action in ({$changes}))"
            .' and coalesce('
            ."(select h.action <> 'staff.revoked' from audit_logs h where {$about} and h.created_at <= {$atColumn} order by h.created_at desc, h.id desc limit 1),"
            ."(select h.action <> 'staff.granted' from audit_logs h where {$about} and h.created_at > {$atColumn} order by h.created_at asc, h.id asc limit 1),"
            ."exists (select 1 from users u where u.id = {$personColumn} and u.platform_role is not null)"
            .'))';

        $bindings = [
            User::class, ...self::CHANGES,
            User::class, ...self::CHANGES,
            User::class, ...self::CHANGES,
        ];

        return [$sql, $bindings];
    }
}
