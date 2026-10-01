<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One account for one address, however the address was typed.
 *
 * The unique index on users.email is case-sensitive, and checkout made the
 * buyer's account with the address exactly as typed: "Ada@example.com" at one
 * checkout and "ada@example.com" at the next were two accounts, and the
 * tickets under one never reached somebody signed in as the other. Every
 * lookup now matches lower(email) (User::forAddress); this holds the table to
 * it, so a race or a path nobody has found yet cannot make a second one.
 *
 * Deactivated and erased accounts included: a closed account keeps its
 * address, and an erased one has a placeholder of its own.
 *
 * Refuses to run where two accounts already share an address. Which of them
 * the tickets, orders and sign-in belong to is a decision about people, not
 * something a migration should guess, so it stops and says where to look.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shared = DB::query()
            ->fromSub(
                DB::table('users')->selectRaw('lower(email)')->groupByRaw('lower(email)')->havingRaw('count(*) > 1'),
                'shared',
            )
            ->count();

        if ($shared > 0) {
            throw new RuntimeException(
                "{$shared} address(es) belong to more than one account, differing only in capital letters. "
                .'Run `php artisan accounts:case-duplicates` to list them, merge or rename each pair, and migrate again.'
            );
        }

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
