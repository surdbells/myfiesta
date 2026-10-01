<?php

namespace App\Services\PersonalData;

use App\Models\Organization;
use App\Services\Payouts\OverdraftPosition;
use App\Services\Payouts\Overdrafts;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
 *   anonymised the row stays and the person leaves it: an order, a ticket, a
 *              door scan. These are financial and admission records, which
 *              both PIPEDA and the NDPR permit keeping, and which the ledger's
 *              append-only trigger would refuse to give up in any case.
 *   kept       the suppression list, which only works if it outlives the
 *              account; the security log, which exists to catch misuse; and
 *              the audit trail, which the database will not let anybody edit.
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

        /*
         * Tables reached through another first, then the rest.
         *
         * An answer given at checkout is found through its order or its
         * ticket, and those through the address on them — which they give up
         * when they are anonymised. Done the other way round, the answers
         * would still be there and nothing would lead to them.
         */
        foreach ([true, false] as $reachedThroughAnother) {
            foreach (['by_user', 'by_email'] as $section) {
                $key = $subject->keyFor($section);

                if ($key === null) {
                    continue;
                }

                foreach (config("personal_data.$section") as $table => $spec) {
                    if (isset($spec['via']) !== $reachedThroughAnother) {
                        continue;
                    }

                    $result = $this->apply($table, $spec, $key);

                    if ($result !== null) {
                        $done[$table] = $result;
                    }
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
     * Two cases, each with a step somebody can actually take.
     *
     * A myFiesta staff account. Its admin sign-in codes go to its address,
     * so it cannot be erased on the strength of a password alone — the thing
     * most likely to have leaked — any more than the address can be moved
     * that way (AccountController::requestEmailChange). And the role would
     * outlive the erasure: this writes past the model, so User::booted()
     * never takes it away, and StaffAccess's rule that the last administrator
     * stays one would be skipped. Another administrator removes the access
     * first, through StaffAccess, and then it is an account like any other.
     *
     * Somebody who is the only owner of an organization. Erasing them would
     * leave events, money and other people's tickets behind a door nobody
     * can open — and their payout details are the organization's, not
     * theirs to take with them. They are told to hand it over or close it
     * first — and when it owes myFiesta money back, that closing is not open
     * to them until the money is recovered or repaid (Overdrafts), so they
     * are told that too rather than sent to a door that will not open.
     */
    public function refusal(Subject $subject): ?string
    {
        // Both, when both stand. Somebody who is staff and the only owner of
        // an organization was told only about the staff access, and the app
        // then offered to open that organization's team with no sentence
        // saying why — and removing the access would only have led to the
        // second refusal.
        $staff = (bool) $subject->user?->isPlatformStaff();
        $owner = $this->onlyOwnerOf($subject, also: $staff);

        $reasons = array_values(array_filter([
            $staff
                ? 'This account has myFiesta staff access, and its admin sign-in codes go to this address, so it '
                    .'cannot be erased from here. Ask another administrator to remove your staff access first'
                : null,
            $owner,
        ]));

        return $reasons === [] ? null : implode('. ', $reasons).', and then ask again.';
    }

    /**
     * Why being the only owner of something stops it, or null when nobody
     * depends on this person that way. Unfinished: refusal() ends it.
     */
    private function onlyOwnerOf(Subject $subject, bool $also = false): ?string
    {
        $stranded = $this->stranded($subject);

        if ($stranded->isEmpty()) {
            return null;
        }

        $names = $stranded->pluck('name')->implode(', ');
        $you = $also ? 'You are also the only owner' : 'You are the only owner';

        $owing = $stranded
            ->filter(fn (Organization $organization) => collect(app(Overdrafts::class)->positionsFor($organization))
                ->contains(fn (OverdraftPosition $position) => $position->isOutstanding()))
            ->pluck('name');

        if ($owing->isNotEmpty()) {
            return "{$you} of {$names}. Erasing your account would leave its events, its money and "
                .'its ticket holders with nobody who can reach them, and '.$owing->implode(', ')
                .' owes myFiesta money, so it cannot be closed until that is recovered from its '
                .'sales or repaid. Make somebody else an owner';
        }

        return "{$you} of {$names}. Erasing your account would leave its events, its money and "
            .'its ticket holders with nobody who can reach them. Make somebody else an owner, or close the '
            .'organization';
    }

    /**
     * The organizations this person is the only owner of: the ones refusal()
     * names.
     *
     * Separate so that somebody deleting their account from the app can be
     * shown which organizations stand in the way, with a way to hand each one
     * over, before they type a password rather than after.
     *
     * @return Collection<int, Organization>
     */
    public function stranded(Subject $subject): Collection
    {
        if ($subject->user === null) {
            return new Collection;
        }

        return Organization::query()
            ->whereHas('members', fn ($q) => $q->where('users.id', $subject->user->id)->where('organization_user.role', 'owner'))
            ->withCount(['members as owners_count' => fn ($q) => $q->where('organization_user.role', 'owner')])
            ->get()
            ->filter(fn (Organization $organization) => $organization->owners_count === 1)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function apply(string $table, array $spec, string|int $key): ?array
    {
        $strategy = $spec['strategy'] ?? null;

        if ($strategy === 'retain') {
            $rows = Rows::of($table, $spec, $key)->count();

            return $rows === 0 ? null : ['action' => 'kept', 'rows' => $rows, 'why' => $spec['reason'] ?? null];
        }

        $this->deleteFiles($table, $spec, $key);

        if ($strategy === 'delete') {
            $rows = Rows::erasable($table, $spec, $key)->delete();

            return $rows === 0 ? null : ['action' => 'deleted', 'rows' => $rows];
        }

        $columns = $spec['columns'] ?? [];

        try {
            // Inside a savepoint: Postgres abandons the whole transaction on a
            // failed statement, so an attempt that might fail has to be one
            // the database can roll back on its own.
            $rows = DB::transaction(fn () => Rows::erasable($table, $spec, $key)->update($this->blanks($table, $columns)));
        } catch (QueryException $e) {
            // A check constraint that insists on a value. An online order must
            // have a buyer address — the rule that stops a web sale pretending
            // to be a walk-up — so the column gets a placeholder rather than
            // nothing. Deliberately at a reserved domain: it is obviously not a
            // person and nothing can be delivered to it.
            $rows = Rows::erasable($table, $spec, $key)->update($this->blanks($table, $columns, tombstones: true));
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

    /**
     * The files a row points at, removed with what it says about them: a
     * profile photo is the person's face, and blanking the column that
     * named it would leave the picture on the disk with nothing leading to
     * it, for ever.
     *
     * Once the erasure has committed, not before: a file cannot be rolled
     * back, and an erasure the database refuses partway must leave the
     * person exactly as they were.
     *
     * @param  array<string, mixed>  $spec
     */
    private function deleteFiles(string $table, array $spec, string|int $key): void
    {
        $columns = $spec['files'] ?? [];

        if ($columns === []) {
            return;
        }

        $paths = Rows::erasable($table, $spec, $key)
            ->get($columns)
            ->flatMap(fn (object $row) => array_values((array) $row))
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();

        if ($paths === []) {
            return;
        }

        $disk = $spec['disk'];

        DB::afterCommit(fn () => Storage::disk($disk)->delete($paths));
    }

    private function isNullable(string $table, string $column): bool
    {
        static $cache = [];

        return $cache["$table.$column"] ??= Schema::getColumns($table)[
            array_search($column, array_column(Schema::getColumns($table), 'name'), true)
        ]['nullable'] ?? true;
    }
}
