<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who did what.
 *
 * The ledger records that money moved and nothing recorded who caused it. For a
 * platform where several staff share an organization and hold other people's
 * money, "who dropped the price to zero at 11pm?" needs an answer that is not
 * somebody's memory.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        $this->type = TicketType::create([
            'event_id' => $this->event->id,
            'name' => 'General',
            'price_amount' => 5000,
            'status' => 'on_sale',
        ]);
    }

    private function signedInAs(Role $role = Role::Owner): User
    {
        $user = User::factory()->create(['name' => 'Ada Okafor']);

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        $user = $user->fresh()->load('organizations');
        Sanctum::actingAs($user, [TokenAbility::Attendee->value, TokenAbility::Organizer->value]);

        return $user;
    }

    private function entries(string $action): Collection
    {
        return AuditLog::where('action', $action)->get();
    }

    // --- it cannot be rewritten ----------------------------------------------

    public function test_an_entry_cannot_be_changed(): void
    {
        $this->signedInAs();
        app(Auditor::class)->record('event.published', $this->event);

        // A log application code can rewrite is not evidence of anything. The
        // same guarantee the ledger has, enforced by the same mechanism.
        $this->expectException(QueryException::class);

        DB::table('audit_logs')->update(['action' => 'something.else']);
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        app(Auditor::class)->record('event.published', $this->event);

        $this->expectException(QueryException::class);

        DB::table('audit_logs')->delete();
    }

    // --- what gets recorded --------------------------------------------------

    public function test_publishing_is_recorded_with_who_did_it(): void
    {
        $user = $this->signedInAs();

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertOk();

        $entry = $this->entries('event.published')->firstOrFail();

        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame($this->event->id, $entry->subject_id);
        $this->assertSame(Event::class, $entry->subject_type);
    }

    public function test_taking_an_event_off_sale_is_recorded_too(): void
    {
        $this->event->update(['status' => 'published']);
        $this->signedInAs();

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'draft'])
            ->assertOk();

        // Not cancelling, but it does stop people buying — worth knowing who
        // decided that.
        $this->assertCount(1, $this->entries('event.unpublished'));
    }

    public function test_cancelling_records_what_actually_happened(): void
    {
        $this->event->update(['status' => 'published']);
        $this->signedInAs();

        $this->postJson("/api/organizer/events/{$this->event->id}/cancel", [
            'reason' => 'The venue has flooded and cannot open.',
            'refund' => false,
        ])->assertOk();

        $entry = $this->entries('event.cancelled')->firstOrFail();

        $this->assertSame('The venue has flooded and cannot open.', $entry->metadata['reason']);
        $this->assertFalse($entry->metadata['refund_requested']);
        // The outcome, not the intent — including refunds a provider refused.
        $this->assertArrayHasKey('refunded', $entry->metadata);
        $this->assertArrayHasKey('failed', $entry->metadata);
    }

    public function test_a_price_change_records_both_sides(): void
    {
        $this->signedInAs();

        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/ticket-types/{$this->type->id}",
            ['name' => 'General', 'price_amount' => 0],
        )->assertOk();

        $entry = $this->entries('ticket.price_changed')->firstOrFail();

        // The entry has to be readable without fetching anything else — this is
        // what somebody looks at when asking who dropped it to zero.
        $this->assertSame(5000, $entry->metadata['before']['price_amount']);
        $this->assertSame(0, $entry->metadata['after']['price_amount']);
        $this->assertSame('CAD', $entry->metadata['currency']);
    }

    public function test_a_save_that_changes_nothing_is_not_recorded(): void
    {
        $this->signedInAs();

        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/ticket-types/{$this->type->id}",
            ['name' => 'General', 'price_amount' => 5000],
        )->assertOk();

        // Recording every PATCH would bury the price change in a hundred no-op
        // saves from somebody tabbing through a form.
        $this->assertCount(0, $this->entries('ticket.price_changed'));
        $this->assertCount(0, $this->entries('ticket.updated'));
    }

    public function test_comps_are_recorded_because_the_ledger_has_nothing_to_say_about_them(): void
    {
        $this->signedInAs();

        $this->postJson("/api/organizer/events/{$this->event->id}/tickets", [
            'ticket_type_id' => $this->type->id,
            'name' => 'Guest List',
            'email' => 'guest@example.com',
            'quantity' => 3,
        ])->assertCreated();

        $entry = $this->entries('ticket.issued')->firstOrFail();

        // Free tickets that count against the room: the only way to fill a
        // venue with no money appearing anywhere.
        $this->assertSame(3, $entry->metadata['count']);
        $this->assertSame('guest@example.com', $entry->metadata['to']);
    }

    // --- the shape of an entry -----------------------------------------------

    public function test_the_actor_survives_the_account_being_deleted(): void
    {
        $user = $this->signedInAs();

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertOk();

        $user->delete();

        $entry = $this->entries('event.published')->firstOrFail()->fresh();

        // Without the denormalised label, erasing one member blanks the actor
        // on every refund they ever processed — the opposite of what an audit
        // trail is for.
        $this->assertSame('Ada Okafor', $entry->actor_label);
        $this->assertSame('Ada Okafor', $entry->actorName());
    }

    public function test_a_failed_write_is_swallowed_rather_than_thrown(): void
    {
        /*
         * An audit write that fails must not roll back the refund it was
         * recording. Losing the record is bad; failing the operation because
         * the record failed is worse.
         *
         * Provoked with an organization id that does not exist, which the
         * foreign key refuses. record() returns null instead of throwing, so
         * the caller carries on.
         */
        $entry = app(Auditor::class)->record(
            'event.published',
            $this->event,
            organizationId: (string) Str::uuid(),
        );

        $this->assertNull($entry);
    }

    public function test_entries_carry_where_the_request_came_from(): void
    {
        $this->signedInAs();

        $this->postJson("/api/organizer/events/{$this->event->id}/publish", ['status' => 'published'])
            ->assertOk();

        // The one field that separates a member acting normally from a session
        // somebody else is holding.
        $this->assertNotNull($this->entries('event.published')->firstOrFail()->ip_address);
    }

    public function test_an_entry_written_outside_a_request_still_works(): void
    {
        // The scheduler and tinker have no request, and asking for one throws.
        $entry = app(Auditor::class)->record('event.published', $this->event);

        $this->assertNotNull($entry);
        $this->assertNull($entry->actor_id);
    }
}
