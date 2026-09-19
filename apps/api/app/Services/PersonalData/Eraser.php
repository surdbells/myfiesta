<?php

namespace App\Services\PersonalData;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Forgetting somebody, as far as the law allows and no further.
 *
 * Erasure here is not a DELETE across the schema, and pretending otherwise
 * would be the dishonest version of this feature. Three things happen,
 * decided per table by config/personal_data.php:
 *
 *   deleted    the row goes: a waitlist entry, a guest on somebody's list, an
 *              invitation. Nothing is owed to anybody on the strength of it.
 *   anonymised the row stays and the person leaves it: an order, a ticket, an
 *              audit entry. These are financial and admission records, which
 *              both PIPEDA and the NDPR permit keeping, and which the ledger's
 *              append-only trigger would refuse to give up in any case.
 *   kept       the suppression list, which only works if it outlives the
 *              account, and the security log, which exists to catch misuse.
 *
 * The person is told which of the three happened to what. "You have been
 * erased" when an anonymised order still sits in a ledger is a claim that does
 * not survive being looked at.
 */
class Eraser
{
    /** @return array<string, mixed> what was done, table by table */
    public function erase(Subject $subject): array
    {
        $done = [];

        foreach (['by_user', 'by_email'] as $section) {
            $key = $subject->keyFor($section);

            if ($key === null) {
                continue;
            }

            foreach (config("personal_data.$section") as $table => $spec) {
                $result = $this->apply($table, $spec, $key);

                if ($result !== null) {
                    $done[$table] = $result;
                }
            }
        }

        foreach ($subject->phones() as $phone) {
            foreach (config('personal_data.by_phone') as $table => $spec) {
                $result = $this->apply($table, $spec, $phone);

                if ($result !== null) {
                    $done[$table] = $result;
                }
            }
        }

        if ($subject->user !== null) {
            $done['account'] = $this->closeAccount($subject);
        }

        return $done;
    }

    /**
     * Why this cannot be done, if it cannot.
     *
     * One case: somebody who is the only owner of an organization. Erasing
     * them would leave events, money and other people's tickets behind a door
     * nobody can open — and their payout details are the organization's, not
     * theirs to take with them. They are told to hand it over or close it
     * first, which is a step they can actually take.
     */
    public function refusal(Subject $subject): ?string
    {
        if ($subject->user === null) {
            return null;
        }

        $stranded = Organization::query()
            ->whereHas('members', fn ($q) => $q->where('users.id', $subject->user->id)->where('organization_user.role', 'owner'))
            ->withCount(['members as owners_count' => fn ($q) => $q->where('organization_user.role', 'owner')])
            ->get()
            ->filter(fn (Organization $organization) => $organization->owners_count === 1);

        if ($stranded->isEmpty()) {
            return null;
        }

        $names = $stranded->pluck('name')->implode(', ');

        return "You are the only owner of {$names}. Erasing your account would leave its events, its money and "
            .'its ticket holders with nobody who can reach them. Make somebody else an owner, or close the '
            .'organization, and then ask again.';
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function apply(string $table, array $spec, string|int $key): ?array
    {
        $strategy = $spec['strategy'] ?? null;
        $column = $spec['key'];

        if ($strategy === 'retain') {
            $rows = $this->query($table, $column, $key)->count();

            return $rows === 0 ? null : ['action' => 'kept', 'rows' => $rows, 'why' => $spec['reason'] ?? null];
        }

        if ($strategy === 'delete') {
            $rows = $this->query($table, $column, $key)->delete();

            return $rows === 0 ? null : ['action' => 'deleted', 'rows' => $rows];
        }

        $columns = $spec['columns'] ?? [];

        try {
            // Inside a savepoint: Postgres abandons the whole transaction on a
            // failed statement, so an attempt that might fail has to be one
            // the database can roll back on its own.
            $rows = DB::transaction(fn () => $this->query($table, $column, $key)->update($this->blanks($table, $columns)));
        } catch (QueryException $e) {
            // A check constraint that insists on a value. An online order must
            // have a buyer address — the rule that stops a web sale pretending
            // to be a walk-up — so the column gets a placeholder rather than
            // nothing. Deliberately at a reserved domain: it is obviously not a
            // person and nothing can be delivered to it.
            $rows = $this->query($table, $column, $key)->update($this->blanks($table, $columns, tombstones: true));
        }

        return $rows === 0 ? null : ['action' => 'anonymised', 'rows' => $rows, 'why' => $spec['reason'] ?? null];
    }

    /**
     * What to put in each column that is being cleared.
     *
     * Null where the column allows it and the row will accept it. Where it
     * will not — an account has to have an address, an online order has to
     * have a buyer — a placeholder that is obviously not a person, unique so
     * that erasing two people does not collide, and at a domain that cannot
     * receive mail.
     *
     * @param  list<string>  $columns
     * @return array<string, string|null>
     */
    private function blanks(string $table, array $columns, bool $tombstones = false): array
    {
        $blanks = [];

        foreach ($columns as $column) {
            if (! $tombstones && $this->isNullable($table, $column)) {
                $blanks[$column] = null;

                continue;
            }

            $blanks[$column] = str_contains($column, 'email')
                ? 'erased-'.Str::lower(Str::random(12)).'@erased.invalid'
                : 'Erased';
        }

        return $blanks;
    }

    /**
     * The account itself: signed out everywhere, and shut.
     *
     * The map anonymises the row because orders and tickets point at it. What
     * it does not cover is the credential — a password hash nobody can use and
     * every token that could still act as them.
     *
     * @return array<string, mixed>
     */
    private function closeAccount(Subject $subject): array
    {
        $user = $subject->user;

        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user::class)
            ->where('tokenable_id', $user->id)
            ->delete();

        $user->forceFill([
            'password' => bcrypt(Str::random(64)),
            'remember_token' => null,
            'email_verified_at' => null,
        ])->saveQuietly();

        if ($user->deleted_at === null) {
            $user->delete();
        }

        return ['action' => 'closed', 'tokens_revoked' => $tokens];
    }

    private function query(string $table, string $column, string|int $key): Builder
    {
        $query = DB::table($table);

        return is_string($key) && str_contains($key, '@')
            ? $query->whereRaw("lower({$column}) = ?", [$key])
            : $query->where($column, $key);
    }

    private function isNullable(string $table, string $column): bool
    {
        static $cache = [];

        return $cache["$table.$column"] ??= Schema::getColumns($table)[
            array_search($column, array_column(Schema::getColumns($table), 'name'), true)
        ]['nullable'] ?? true;
    }
}
