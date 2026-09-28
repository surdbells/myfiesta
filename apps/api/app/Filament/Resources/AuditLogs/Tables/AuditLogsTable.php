<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Filament\Resources\AuditLogs\AuditEntries;
use App\Filament\Support\Listing;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\StaffRoles;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The audit trail as a listing: newest first, searchable by who, what and
 * what it happened to, and filterable by action, actor, organization and
 * date.
 *
 * Deliberately without bulk actions, row actions other than View, or an
 * export. Times are UTC, as they are stored.
 */
final class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'audit entries')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['actor:id,name,email,platform_role', 'organization:id,name']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When (UTC)')
                    ->dateTime('j M Y, H:i:s')
                    ->sortable(),
                TextColumn::make('actor_label')
                    ->label('Who')
                    ->state(fn (AuditLog $record): string => $record->actorName())
                    // As they were when they did it, not as they are now: an
                    // organizer made staff since did not do this as staff.
                    ->description(fn (AuditLog $record): ?string => match (true) {
                        $record->isSystem() => null,
                        AuditEntries::isImpersonation($record) => 'myFiesta staff, acting as the organization',
                        default => StaffRoles::shared()->describe($record->actor, $record->created_at),
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('audit_logs.actor_label', 'ilike', self::like($search))
                        ->orWhereIn('audit_logs.actor_id', User::query()
                            ->where('name', 'ilike', self::like($search))
                            ->orWhere('email', 'ilike', self::like($search))
                            ->select('id')))),
                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'impersonation.'), str_starts_with($state, 'staff.') => 'warning',
                        str_contains($state, 'refund'), str_contains($state, 'void'), str_contains($state, 'revoked'),
                        str_contains($state, 'taken_down'), str_contains($state, 'cancelled'), str_contains($state, 'forgotten') => 'danger',
                        default => 'gray',
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('audit_logs.action', 'ilike', self::like($search)))
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('About')
                    ->state(fn (AuditLog $record): string => AuditEntries::subject($record))
                    ->url(fn (AuditLog $record): ?string => AuditEntries::subjectUrl($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->where('audit_logs.subject_type', 'ilike', self::like($search))
                        ->orWhereRaw('audit_logs.subject_id::text ilike ?', [self::like($search)]))),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->placeholder('Platform')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'audit_logs.organization_id',
                        Organization::withTrashed()->where('name', 'ilike', self::like($search))->select('id'),
                    )),
                TextColumn::make('ip_address')
                    ->label('From')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::distinct('action')),
                SelectFilter::make('actor_id')
                    ->label('Who')
                    ->relationship('actor', 'name')
                    ->searchable(),
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                    ->searchable(),
                SelectFilter::make('subject_type')
                    ->label('About')
                    ->options(fn (): array => collect(self::distinct('subject_type'))
                        ->mapWithKeys(fn (string $type) => [$type => Str::headline(class_basename($type))])
                        ->sort()
                        ->all()),
                // Staff when they did it (StaffRoles), not staff now.
                TernaryFilter::make('staff')
                    ->label('Done by')
                    ->placeholder('Anybody')
                    ->trueLabel('myFiesta staff')
                    ->falseLabel('Organizers, buyers and the system')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereRaw(...StaffRoles::heldSql('audit_logs.actor_id', 'audit_logs.created_at')),
                        false: function (Builder $query): Builder {
                            [$held, $bindings] = StaffRoles::heldSql('audit_logs.actor_id', 'audit_logs.created_at');

                            return $query->whereRaw('not '.$held, $bindings);
                        },
                    ),
                TernaryFilter::make('impersonating')
                    ->label('Acting as an organization')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereRaw("(audit_logs.metadata::jsonb -> 'impersonating') is not null"),
                        false: fn (Builder $query): Builder => $query->whereRaw("(audit_logs.metadata::jsonb -> 'impersonating') is null"),
                    ),
                Listing::dateRange('created', 'created_at', 'When'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Every value a column has held, for a filter's options. Cached for a few
     * minutes: new actions appear when code ships, not every second.
     *
     * @return array<string, string>
     */
    private static function distinct(string $column): array
    {
        return Cache::remember('admin:audit-log:'.$column, 300, fn (): array => AuditLog::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all());
    }

    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
    }
}
