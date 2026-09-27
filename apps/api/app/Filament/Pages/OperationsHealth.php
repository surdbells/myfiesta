<?php

namespace App\Filament\Pages;

use App\Enums\PlatformRole;
use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Filament\Resources\OrganizationIdentityDocuments\OrganizationIdentityDocumentResource;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Models\User;
use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\FailedJobs;
use App\Services\Analytics\OperationsHealth as Health;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * Whether the machinery behind the platform is keeping up.
 *
 * Queue jobs that gave up, webhook deliveries that failed, numbers that said
 * STOP, and the queues of work waiting on a person — privacy requests,
 * identity documents, payout requests — with the schedule the background
 * jobs run to. Only what the platform records; where it records nothing, as
 * with when the scheduler last ran, the page says so.
 *
 * Administrators and support may read it. Only administrators may retry or
 * forget a failed job, and each time is written to the audit trail.
 */
class OperationsHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Operations health';

    protected static ?string $slug = 'operations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPlatformRole(PlatformRole::Admin, PlatformRole::Support) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $failed = app(Health::class)->failedJobs()['count'];
        } catch (Throwable) {
            return null;
        }

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.charts.styles'),
            View::make('filament.pages.operations-summary')->viewData(fn (): array => $this->summary()),
            Section::make('Failed queue jobs')
                ->description('Jobs that ran out of attempts, newest first. The payload is never shown; the error is its first line. '
                    .(FailedJobs::mayManage($this->staff()) ? 'Retrying puts a job back on its queue — anything it sends goes again.' : 'Only administrators may retry or forget them.'))
                ->schema([EmbeddedTable::make()]),
            View::make('filament.pages.operations-detail')->viewData(fn (): array => $this->detail()),
        ]);
    }

    private function staff(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $health = app(Health::class);
        $s = $health->snapshot();
        // Payout amounts are for whoever may open the payout requests.
        $seesMoney = PayoutRequestResource::canViewAny();
        $ago = fn (?string $at): ?string => $at ? CarbonImmutable::parse($at, 'UTC')->diffForHumans() : null;

        $payoutHints = collect($s['payouts']['currencies'])
            ->map(fn (array $row, string $currency) => number_format($row['count']).' in '.$currency
                .($seesMoney ? ' ('.Format::money($row['amount'], $currency).')' : '')
                .($row['oldest'] ? ', oldest '.$ago($row['oldest']) : ''))
            ->implode(' · ');

        return [
            'tiles' => [
                [
                    'label' => 'Failed queue jobs',
                    'value' => $s['failed_jobs']['present'] ? number_format($s['failed_jobs']['count']) : 'Not recorded',
                    'hint' => $s['failed_jobs']['latest'] ? 'Latest '.$ago($s['failed_jobs']['latest']) : 'None on record',
                ],
                [
                    'label' => 'Webhook deliveries failed',
                    'value' => number_format($s['webhooks']['failed']),
                    'hint' => number_format($s['webhooks']['retrying']).' retrying · '.number_format($s['webhooks']['disabled_endpoints']).' '.str('endpoint')->plural($s['webhooks']['disabled_endpoints']).' switched off',
                ],
                [
                    'label' => 'SMS opt-outs',
                    'value' => $s['sms']['present'] ? number_format($s['sms']['numbers']) : 'Not recorded',
                    'hint' => $s['sms']['present'] ? number_format($s['sms']['last_30_days']).' in the last 30 days' : null,
                ],
                [
                    'label' => 'Privacy requests to action',
                    'value' => number_format($s['data_requests']['to_action']),
                    'hint' => ($s['data_requests']['overdue'] > 0 ? number_format($s['data_requests']['overdue']).' overdue · ' : '')
                        .($s['data_requests']['next_due'] ? 'next due '.$ago($s['data_requests']['next_due']).' · ' : '')
                        .number_format($s['data_requests']['awaiting_proof']).' awaiting the requester',
                    'href' => self::urlIf(DataRequestResource::class),
                ],
                [
                    'label' => 'Identity documents to review',
                    'value' => number_format($s['identity']['pending']),
                    'hint' => $s['identity']['oldest'] ? 'Oldest sent '.$ago($s['identity']['oldest']) : 'Nothing waiting',
                    'href' => self::urlIf(OrganizationIdentityDocumentResource::class),
                ],
                [
                    'label' => 'Payout requests waiting',
                    'value' => number_format($s['payouts']['count']),
                    'hint' => $payoutHints !== '' ? $payoutHints : 'Nothing waiting',
                    'href' => self::urlIf(PayoutRequestResource::class),
                ],
            ],
            'queues' => collect($s['failed_jobs']['queues'])
                ->map(fn (int $jobs, string $queue) => ['label' => $queue, 'value' => $jobs])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(): array
    {
        $health = app(Health::class);
        $webhooks = $health->webhooks();

        return [
            'webhooks' => $webhooks,
            'endpoints' => array_map(fn (array $endpoint) => [
                'label' => $endpoint['organization'],
                'value' => $endpoint['failures'],
                'hint' => $endpoint['url'].' · endpoint '.$endpoint['ref'].($endpoint['disabled'] ? ' · switched off' : ''),
            ], $webhooks['endpoints']),
            'schedule' => $health->schedule(),
        ];
    }

    /** @param class-string $resource */
    private static function urlIf(string $resource): ?string
    {
        try {
            return $resource::canViewAny() ? $resource::getUrl('index') : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        $health = app(Health::class);

        return $table
            ->records(fn (?string $search, array $filters, int $page, int $recordsPerPage, ?string $sortDirection): LengthAwarePaginator => $health->failedJobPage(
                $search,
                $filters['queue']['value'] ?? null,
                $page,
                $recordsPerPage,
                $sortDirection ?? 'desc',
            ))
            // Filament keeps the selection as a collection. Ticking "select
            // all" sends no keys, only the ones unticked since: that is every
            // job the search and queue filter match, across every page.
            ->resolveSelectedRecordsUsing(fn (array $keys, bool $isTrackingDeselectedKeys, array $deselectedKeys): Collection => collect(
                $isTrackingDeselectedKeys
                    ? $health->failedJobsMatching($this->getTableSearch(), $this->getTableFilterState('queue')['value'] ?? null, array_map('strval', $deselectedKeys))
                    : $health->failedJobsByUuid(array_map('strval', $keys)),
            ))
            ->searchable()
            ->searchPlaceholder('Job, queue, error or id')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('job')
                    ->label('Job')
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['uuid'])
                    ->tooltip(fn (array $record): ?string => $record['job_class']),
                TextColumn::make('queue')->badge()->color('gray'),
                TextColumn::make('connection')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('exception')
                    ->label('Error')
                    ->limit(120)
                    ->tooltip(fn (array $record): string => $record['exception'])
                    ->wrap(),
                TextColumn::make('failed_at')
                    ->label('Failed')
                    ->sortable()
                    ->since()
                    ->dateTimeTooltip(timezone: 'UTC'),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->options(fn (): array => collect($health->failedJobs()['queues'])->keys()->mapWithKeys(fn (string $queue) => [$queue => $queue])->all()),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (): bool => FailedJobs::mayManage($this->staff()))
                    ->requiresConfirmation()
                    ->modalHeading('Retry this job?')
                    ->modalDescription('It goes back on its queue and runs again. Anything it sends — mail, messages, webhooks — is sent again. Recorded in the audit trail under your name.')
                    ->action(fn (array $record) => $this->retry([$record['uuid']])),
                Action::make('forget')
                    ->label('Forget')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (): bool => FailedJobs::mayManage($this->staff()))
                    ->requiresConfirmation()
                    ->modalHeading('Forget this failed job?')
                    ->modalDescription('The job is not run, and this record of its failure is deleted. Recorded in the audit trail under your name.')
                    ->action(fn (array $record) => $this->forget($record['uuid'])),
            ])
            ->toolbarActions([
                BulkAction::make('retrySelected')
                    ->label('Retry selected')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (): bool => FailedJobs::mayManage($this->staff()))
                    ->requiresConfirmation()
                    ->modalDescription('Each goes back on its queue and runs again; anything they send is sent again. Each is recorded in the audit trail under your name.')
                    ->action(fn (Collection $records) => $this->retry($records->pluck('uuid')->all())),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('Every queued job that ran has finished, or nothing matches the search and filter.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /** @param list<string> $uuids */
    private function retry(array $uuids): void
    {
        $staff = $this->staff();
        abort_unless(FailedJobs::mayManage($staff), 403);

        $done = collect($uuids)->filter(fn (string $uuid): bool => app(FailedJobs::class)->retry($uuid, $staff))->count();
        $missed = count($uuids) - $done;

        Notification::make()
            ->title($done === 1 ? 'Job put back on its queue' : number_format($done).' jobs put back on their queues')
            ->body($missed > 0 ? number_format($missed).' could not be retried — already retried or forgotten by somebody else.' : null)
            ->status($missed > 0 ? 'warning' : 'success')
            ->send();

        $this->flushCachedTableRecords();
    }

    private function forget(string $uuid): void
    {
        $staff = $this->staff();
        abort_unless(FailedJobs::mayManage($staff), 403);

        $forgotten = app(FailedJobs::class)->forget($uuid, $staff);

        Notification::make()
            ->title($forgotten ? 'Failed job forgotten' : 'Already gone')
            ->body($forgotten ? null : 'Somebody else retried or forgot it first.')
            ->status($forgotten ? 'success' : 'warning')
            ->send();

        $this->flushCachedTableRecords();
    }
}
