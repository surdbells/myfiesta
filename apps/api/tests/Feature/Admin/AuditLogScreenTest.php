<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Filament\Resources\AuditLogs\AuditEntries;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ListSensitiveDataAccesses;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\AuditLogs\SensitiveDataAccessResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\SensitiveDataAccess;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Staff\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The audit log viewer: administrators and finance read it, it searches and
 * filters, it shows an entry's details as JSON without anything that names a
 * credential — and it offers no way, for anybody, to change an entry.
 */
class AuditLogScreenTest extends TestCase
{
    use AnalyticsFixtures, RefreshDatabase;

    public function test_administrators_and_finance_read_it_and_support_does_not(): void
    {
        foreach ([PlatformRole::Admin, PlatformRole::Finance] as $role) {
            $this->signIn($this->staffMember($role));
            $this->assertTrue(AuditLogResource::canViewAny(), $role->value);
            $this->assertTrue(SensitiveDataAccessResource::canViewAny(), $role->value);
            $this->get('/admin/audit-log')->assertSuccessful();
            $this->get('/admin/sensitive-data-reads')->assertSuccessful();
        }

        $this->signIn($this->staffMember(PlatformRole::Support));
        $this->assertFalse(AuditLogResource::canViewAny());
        $this->get('/admin/audit-log')->assertForbidden();
        $this->get('/admin/sensitive-data-reads')->assertForbidden();

        $this->signIn(User::factory()->create(['email_verified_at' => now()]));
        $this->assertFalse(AuditLogResource::canViewAny());
    }

    public function test_nobody_can_create_edit_or_delete_an_entry_from_it(): void
    {
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));
        $entry = app(Auditor::class)->record('staff.granted', $admin, $admin);

        foreach ([AuditLogResource::class, SensitiveDataAccessResource::class] as $resource) {
            $this->assertFalse($resource::canCreate(), $resource);
            $this->assertFalse($resource::canEdit($entry), $resource);
            $this->assertFalse($resource::canDelete($entry), $resource);
            $this->assertFalse($resource::canDeleteAny(), $resource);
            $this->assertFalse($resource::canForceDelete($entry), $resource);
            $this->assertFalse($resource::canForceDeleteAny(), $resource);
            $this->assertFalse($resource::canRestore($entry), $resource);
            $this->assertFalse($resource::canReplicate($entry), $resource);
            $this->assertFalse($resource::canReorder(), $resource);
        }

        $this->assertSame(['index', 'view'], array_keys(AuditLogResource::getPages()));
        $this->assertSame(['index'], array_keys(SensitiveDataAccessResource::getPages()));

        $list = Livewire::test(ListAuditLogs::class);
        $table = $list->instance()->getTable();

        $this->assertSame([], $table->getToolbarActions(), 'No bulk or toolbar actions.');
        $this->assertSame([], $table->getBulkActions());
        $this->assertSame(['view'], array_values(array_map(fn ($action) => $action->getName(), $table->getActions())), 'View is the only thing a row offers.');
        $this->assertFalse($table->isSelectionEnabled());

        $list->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(ViewAuditLog::class, ['record' => $entry->getKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');

        $reads = Livewire::test(ListSensitiveDataAccesses::class)->instance()->getTable();
        $this->assertSame([], $reads->getActions());
        $this->assertSame([], $reads->getToolbarActions());
    }

    public function test_it_searches_by_who_what_and_what_it_was_about_and_filters(): void
    {
        $this->stopTheClock();
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));
        $finance = $this->staffMember(PlatformRole::Finance);
        $finance->update(['name' => 'Fola Finance']);
        $organization = Organization::factory()->create(['name' => 'Ibadan Beats']);

        $auditor = app(Auditor::class);
        $granted = $auditor->record('staff.granted', $finance, $admin);
        $refunded = $auditor->record('order.tickets_resent', $organization, $finance, $organization->id, ['count' => 2]);
        $old = $auditor->record('organization.logo_updated', $organization, null, $organization->id);
        AuditLog::query()->whereKey($old->id)->toBase()->getConnection()->statement(
            'insert into audit_logs (id, action, created_at) values (?, ?, ?)',
            [(string) Str::uuid(), 'event.cancelled', '2026-01-02 10:00:00'],
        );
        $cancelled = AuditLog::query()->where('action', 'event.cancelled')->sole();

        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$granted, $refunded, $old, $cancelled])
            ->searchTable('Fola')
            ->assertCanSeeTableRecords([$refunded])
            ->assertCanNotSeeTableRecords([$granted, $old])
            ->searchTable('tickets_resent')
            ->assertCanSeeTableRecords([$refunded])
            ->assertCanNotSeeTableRecords([$granted])
            ->searchTable($finance->id)
            ->assertCanSeeTableRecords([$granted])
            ->assertCanNotSeeTableRecords([$refunded])
            ->searchTable('Ibadan')
            ->assertCanSeeTableRecords([$refunded, $old])
            ->assertCanNotSeeTableRecords([$granted])
            ->searchTable(null)
            ->filterTable('action', ['staff.granted'])
            ->assertCanSeeTableRecords([$granted])
            ->assertCanNotSeeTableRecords([$refunded, $old])
            ->resetTableFilters()
            ->filterTable('actor_id', $finance->id)
            ->assertCanSeeTableRecords([$refunded])
            ->assertCanNotSeeTableRecords([$granted])
            ->resetTableFilters()
            ->filterTable('organization_id', $organization->id)
            ->assertCanSeeTableRecords([$refunded, $old])
            ->assertCanNotSeeTableRecords([$granted, $cancelled])
            ->resetTableFilters()
            ->filterTable('staff', true)
            ->assertCanSeeTableRecords([$granted, $refunded])
            ->assertCanNotSeeTableRecords([$old, $cancelled])
            ->resetTableFilters()
            ->filterTable('created', ['from' => '2026-01-01', 'until' => '2026-01-31'])
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$granted, $refunded, $old]);
    }

    public function test_an_entry_shows_its_details_as_json_without_credentials(): void
    {
        $admin = $this->signIn($this->staffMember(PlatformRole::Finance));
        $entry = app(Auditor::class)->record('payout_details.revealed', $admin, $admin, null, [
            'payout_detail_id' => 'pd_123',
            'nested' => ['reset_token' => 'abc-secret-value', 'kept' => 'visible'],
            'ticket_code' => 'TCK-SHOULD-NOT-SHOW',
        ]);

        $this->get(AuditLogResource::getUrl('view', ['record' => $entry]))
            ->assertSuccessful()
            ->assertSee('payout_details.revealed')
            ->assertSee('&quot;payout_detail_id&quot;: &quot;pd_123&quot;', false)
            ->assertSee('visible')
            ->assertSee('[not shown]')
            ->assertDontSee('abc-secret-value')
            ->assertDontSee('TCK-SHOULD-NOT-SHOW');
    }

    public function test_an_entry_about_an_organization_links_to_its_page(): void
    {
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));
        $organization = Organization::factory()->create(['name' => 'Ibadan Beats']);
        $entry = app(Auditor::class)->record('organization.suspended', $organization, $admin, $organization->id, ['reason' => 'Chargebacks']);

        $page = OrganizationResource::getUrl('view', ['record' => $organization]);

        $this->assertSame($page, AuditEntries::subjectUrl($entry));
        $this->assertSame('Organization · '.substr($organization->id, 0, 8), AuditEntries::subject($entry));

        // On the list and on the entry itself, both of which read it from here.
        Livewire::test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$entry])
            ->assertSee($page, false);

        $this->get(AuditLogResource::getUrl('view', ['record' => $entry]))
            ->assertSuccessful()
            ->assertSee($page, false);

        // Finance reads the trail and may open every organization, so is
        // linked too. The link is only ever for somebody who may open it.
        $this->signIn($this->staffMember(PlatformRole::Finance));
        $this->assertSame($page, AuditEntries::subjectUrl($entry));

        $this->signIn(User::factory()->create(['email_verified_at' => now()]));
        $this->assertNull(AuditEntries::subjectUrl($entry));
    }

    public function test_sensitive_data_reads_name_whose_record_was_read(): void
    {
        $finance = $this->signIn($this->staffMember(PlatformRole::Finance));
        $organization = Organization::factory()->create(['name' => 'Abuja Sounds']);
        $detail = OrganizationPayoutDetail::query()->forceCreate([
            'organization_id' => $organization->id,
            ...$this->payoutDetailColumns(),
        ]);

        $read = SensitiveDataAccess::record($finance, OrganizationPayoutDetail::class, $detail->id, 'viewed', '10.0.0.1');

        Livewire::test(ListSensitiveDataAccesses::class)
            ->assertCanSeeTableRecords([$read])
            ->assertSee('Abuja Sounds')
            ->assertSee('Payout details')
            ->filterTable('kind', 'identity')
            ->assertCanNotSeeTableRecords([$read]);
    }

    /**
     * As they were when they did it, not as they are now.
     *
     * Found after an organizer was made an administrator for a click-through:
     * every payout she had asked for and every key she had made the week
     * before read "myFiesta staff · Administrator", and the staff filter
     * found 52 of 59 entries, because both asked what her role was now.
     */
    public function test_who_did_it_is_read_as_they_were_then_not_as_they_are_now(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'));
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));
        $organization = Organization::factory()->create(['name' => 'Lagos Nights']);
        $ada = User::factory()->create(['name' => 'Ada Okoro', 'email_verified_at' => now()]);
        $auditor = app(Auditor::class);

        // An organizer, asking to be paid.
        $this->travelTo(CarbonImmutable::parse('2026-09-12 10:00:00', 'UTC'));
        $asked = $auditor->record('payout_request.created', $organization, $ada, $organization->id);

        // Made an administrator from the server's console, and paying one as staff.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 10:32:55', 'UTC'));
        app(StaffAccess::class)->grant($ada->email, PlatformRole::Admin, null);
        $granted = AuditLog::query()->where('action', 'staff.granted')->sole();

        $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00:00', 'UTC'));
        $paid = $auditor->record('payout_request.paid', $organization, $ada->fresh(), $organization->id);

        // Revoked the next day, and an organizer again.
        $this->travelTo(CarbonImmutable::parse('2026-09-29 09:00:00', 'UTC'));
        app(StaffAccess::class)->revoke($ada->fresh(), $admin);
        $revoked = AuditLog::query()->where('action', 'staff.revoked')->sole();

        $this->travelTo(CarbonImmutable::parse('2026-09-30 09:00:00', 'UTC'));
        $later = $auditor->record('api_key.created', $organization, $ada->fresh(), $organization->id);

        Livewire::test(ListAuditLogs::class)
            ->assertTableColumnHasDescription('actor_label', null, $asked)
            ->assertTableColumnHasDescription('actor_label', 'myFiesta staff · Administrator', $paid)
            ->assertTableColumnHasDescription('actor_label', null, $later)
            ->assertTableColumnHasDescription('actor_label', 'myFiesta staff · Administrator', $revoked)
            // Only what was done as staff — hers while she was, and the
            // revoking administrator's — and not the console's grant.
            ->filterTable('staff', true)
            ->assertCanSeeTableRecords([$paid, $revoked])
            ->assertCanNotSeeTableRecords([$asked, $later, $granted])
            ->resetTableFilters()
            ->filterTable('staff', false)
            ->assertCanSeeTableRecords([$asked, $later, $granted])
            ->assertCanNotSeeTableRecords([$paid, $revoked]);

        $this->get(AuditLogResource::getUrl('view', ['record' => $asked]))
            ->assertSuccessful()
            ->assertSee('Ada Okoro')
            ->assertDontSee('Ada Okoro (myFiesta staff');

        $this->get(AuditLogResource::getUrl('view', ['record' => $paid]))
            ->assertSuccessful()
            ->assertSee('Ada Okoro (myFiesta staff · Administrator)');
    }

    /** Scheduled work and the server's console are not a person whose account has gone. */
    public function test_what_nobody_signed_in_did_is_named_for_what_did_it(): void
    {
        $this->signIn($this->staffMember(PlatformRole::Admin));
        $someone = User::factory()->create(['name' => 'Kemi Ade', 'email_verified_at' => now()]);

        $scheduled = app(Auditor::class)->record('door_pass.opened');
        app(StaffAccess::class)->grant($someone->email, PlatformRole::Support, null);
        $fromTheConsole = AuditLog::query()->where('action', 'staff.granted')->sole();

        $this->assertSame('System', $scheduled->actorName());
        $this->assertSame('Server console', $fromTheConsole->actorName());

        Livewire::test(ListAuditLogs::class)
            ->assertTableColumnStateSet('actor_label', 'System', $scheduled)
            ->assertTableColumnStateSet('actor_label', 'Server console', $fromTheConsole)
            ->assertTableColumnHasDescription('actor_label', null, $scheduled)
            ->assertDontSee('Someone no longer on the account');
    }

    public function test_a_sensitive_read_is_labelled_with_the_role_held_when_it_was_read(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'));
        $admin = $this->signIn($this->staffMember(PlatformRole::Admin));
        $organization = Organization::factory()->create(['name' => 'Abuja Sounds']);
        $detail = OrganizationPayoutDetail::query()->forceCreate([
            'organization_id' => $organization->id,
            ...$this->payoutDetailColumns(),
        ]);
        $fola = User::factory()->create(['name' => 'Fola Finance', 'email_verified_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00:00', 'UTC'));
        app(StaffAccess::class)->grant($fola->email, PlatformRole::Finance, $admin);

        $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'UTC'));
        $read = SensitiveDataAccess::record($fola, OrganizationPayoutDetail::class, $detail->id, 'viewed');

        // Gone since: the read was still made as finance.
        $this->travelTo(CarbonImmutable::parse('2026-09-04 09:00:00', 'UTC'));
        app(StaffAccess::class)->revoke($fola->fresh(), $admin);

        Livewire::test(ListSensitiveDataAccesses::class)
            ->assertTableColumnHasDescription('user.name', 'myFiesta staff · Finance', $read);
    }

    /** @return array<string, mixed> the columns a payout detail cannot be saved without */
    private function payoutDetailColumns(): array
    {
        $columns = [];

        foreach (DB::select("select column_name, data_type from information_schema.columns where table_name = 'organization_payout_details' and is_nullable = 'NO' and column_default is null and column_name not in ('id', 'organization_id')") as $column) {
            $columns[$column->column_name] = match ($column->data_type) {
                'boolean' => false,
                'integer', 'bigint', 'smallint' => 0,
                'timestamp with time zone', 'timestamp without time zone' => now(),
                'character' => 'CAD',
                default => 'x',
            };
        }

        return $columns;
    }
}
