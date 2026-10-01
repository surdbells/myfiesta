<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Accounts that share an address, differing only in capital letters.
 *
 * Checkout used to make the buyer's account with the address exactly as it
 * was typed, so one person could become two. The migration that holds the
 * table to one account per address refuses to run while any pair is left,
 * and names this command; this lists them for somebody to decide which
 * account each pair's tickets, orders and sign-in belong to.
 *
 * By account id and the day each was made, numbered by address, and nothing
 * else: the addresses and names are personal data, and whoever reads this
 * opens each account in the admin panel to see them, where that is logged.
 *
 * Fails while any pair is left, so a deploy script can stop on it.
 */
class ListCaseDuplicateAccounts extends Command
{
    protected $signature = 'accounts:case-duplicates';

    protected $description = 'List accounts whose addresses differ only in capital letters';

    public function handle(): int
    {
        $accounts = DB::table('users')
            ->select(['id', 'created_at'])
            ->selectRaw('lower(email) as address')
            ->whereIn(DB::raw('lower(email)'), DB::table('users')
                ->selectRaw('lower(email)')
                ->groupByRaw('lower(email)')
                ->havingRaw('count(*) > 1'))
            ->orderByRaw('lower(email)')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No two accounts share an address.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($accounts->groupBy('address')->values() as $number => $pair) {
            foreach ($pair as $account) {
                $rows[] = [$number + 1, $account->id, $account->created_at];
            }
        }

        $shared = $accounts->pluck('address')->unique()->count();

        $this->error("{$shared} address(es) belong to more than one account.");
        $this->table(['Address', 'Account', 'Created'], $rows);
        $this->line('Open each account in the admin panel to decide which one the tickets, orders and sign-in belong to.');

        return self::FAILURE;
    }
}
