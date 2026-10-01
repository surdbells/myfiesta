<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use App\Services\Checkout\TicketIssuer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Admin\SupportFixtures;
use Tests\TestCase;

/**
 * One person, one account, however their address was typed.
 *
 * Checkout made the buyer's account with the address exactly as typed, and
 * the unique index on users.email is case-sensitive, so "Ada@example.com" at
 * one checkout and "ada@example.com" at the next were two people. Every
 * path that finds an account by its address now matches lower(email), and an
 * index holds the table to it — behind a migration that stops, and names the
 * command that lists them, where two such accounts already exist.
 */
class OneAccountPerAddressTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    private const MIGRATION = 'migrations/2026_10_01_000100_one_account_for_an_address_however_it_is_typed.php';

    /** Two accounts at one address, as they could be made before the index. */
    private function duplicates(): array
    {
        DB::statement('DROP INDEX users_email_lower_unique');

        return [
            User::factory()->create(['email' => 'ada@example.com', 'created_at' => now()->subYear()]),
            User::factory()->create(['email' => 'Ada@Example.com']),
        ];
    }

    public function test_the_database_refuses_a_second_account_for_the_same_address(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => 'ADA@example.com']);
    }

    public function test_the_command_lists_accounts_sharing_an_address_by_id_and_date_only(): void
    {
        $this->assertSame(0, Artisan::call('accounts:case-duplicates'));
        $this->assertStringContainsString('No two accounts share an address', Artisan::output());

        [$first, $second] = $this->duplicates();
        User::factory()->create(['email' => 'grace@example.com']);

        $this->assertSame(1, Artisan::call('accounts:case-duplicates'));
        $output = Artisan::output();

        $this->assertStringContainsString('1 address(es) belong to more than one account', $output);
        $this->assertStringContainsString($first->id, $output);
        $this->assertStringContainsString($second->id, $output);

        // Nothing that says who they are.
        $this->assertStringNotContainsStringIgnoringCase('ada@example.com', $output);
        $this->assertStringNotContainsString($first->name, $output);
        $this->assertStringNotContainsString('grace', $output);
    }

    public function test_the_migration_stops_and_names_the_command_while_two_accounts_share_an_address(): void
    {
        $this->duplicates();
        $migration = require database_path(self::MIGRATION);

        try {
            $migration->up();
            $this->fail('The index went on over two accounts at one address.');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('php artisan accounts:case-duplicates', $refused->getMessage());
        }

        $this->assertFalse($this->indexed());
    }

    public function test_the_migration_puts_the_index_on_once_every_address_is_one_account(): void
    {
        DB::statement('DROP INDEX users_email_lower_unique');
        User::factory()->create(['email' => 'Ada@Example.com']);

        (require database_path(self::MIGRATION))->up();

        $this->assertTrue($this->indexed());
    }

    public function test_a_checkout_under_another_spelling_of_an_address_reaches_the_same_account(): void
    {
        $ada = User::factory()->create(['email' => 'ada@example.com']);
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);

        $order = $this->paidOrder($event, $type, 1, ['buyer_email' => 'Ada@Example.COM']);
        $issued = app(TicketIssuer::class)->issueFor($order);

        $this->assertSame($ada->id, $issued[0]->owner_user_id);
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['ada@example.com'])->count());
    }

    public function test_a_new_address_typed_with_capitals_makes_one_account_every_later_spelling_finds(): void
    {
        $event = $this->event($this->organization());
        $type = $this->ticketType($event);
        $issuer = app(TicketIssuer::class);

        $first = $issuer->issueFor($this->paidOrder($event, $type, 1, ['buyer_email' => 'Grace@Example.com', 'buyer_name' => 'Grace']));
        $second = $issuer->issueFor($this->paidOrder($event, $type, 1, ['buyer_email' => 'grace@example.com', 'buyer_name' => 'Grace']));
        $comp = $issuer->issueComp($event->id, $type->id, 'GRACE@example.com', 'Grace');

        $account = User::whereRaw('lower(email) = ?', ['grace@example.com'])->sole();
        $this->assertSame('grace@example.com', $account->email);
        $this->assertSame($account->id, $first[0]->owner_user_id);
        $this->assertSame($account->id, $second[0]->owner_user_id);
        $this->assertSame($account->id, $comp->owner_user_id);
    }

    public function test_an_account_made_under_capitals_signs_in_and_resets_under_any_spelling(): void
    {
        Notification::fake();
        $ada = User::factory()->create(['email' => 'Ada@Example.com', 'password' => 'the right one 12']);

        $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'the right one 12'])->assertOk();

        $this->postJson('/api/auth/forgot-password', ['email' => 'ADA@example.com'])->assertOk();
        Notification::assertSentTo($ada, ResetPassword::class);

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($ada),
            'email' => 'ada@example.com',
            'password' => 'a whole new one 9',
            'password_confirmation' => 'a whole new one 9',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'a whole new one 9'])->assertOk();
    }

    public function test_a_ticket_sent_to_another_spelling_of_an_address_reaches_its_account(): void
    {
        Mail::fake();
        $grace = User::factory()->create(['email' => 'grace@example.com']);
        $holder = User::factory()->create();
        $event = $this->event($this->organization());
        $ticket = $this->paidOrder($event, $this->ticketType($event), 1, ['user_id' => $holder->id, 'buyer_email' => $holder->email])->tickets()->sole();
        $ticket->update(['owner_user_id' => $holder->id]);

        Sanctum::actingAs($holder, [TokenAbility::Attendee->value]);

        $this->postJson("/api/tickets/{$ticket->id}/transfer", ['email' => 'Grace@Example.com', 'name' => 'Grace'])->assertOk();

        $this->assertSame($grace->id, $ticket->fresh()->owner_user_id);
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['grace@example.com'])->count());
    }

    private function indexed(): bool
    {
        return DB::table('pg_indexes')->where('tablename', 'users')->where('indexname', 'users_email_lower_unique')->exists();
    }
}
