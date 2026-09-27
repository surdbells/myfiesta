<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListSensitiveDataAccesses;
use App\Filament\Support\Listing;
use App\Models\OrganizationIdentityDocument;
use App\Models\OrganizationPayoutDetail;
use App\Models\SensitiveDataAccess;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Who opened banking or identity data, and when.
 *
 * The other half of the trail: the audit log records what somebody did, this
 * records what somebody read. It names the record that was read and whose it
 * was — never the data itself, which stays behind its own screens and their
 * own logging.
 *
 * The same people as the audit log may read it, and nobody may change it.
 */
class SensitiveDataAccessResource extends Resource
{
    protected static ?string $model = SensitiveDataAccess::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Sensitive data reads';

    protected static ?string $modelLabel = 'sensitive data read';

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 51;

    protected static ?string $slug = 'sensitive-data-reads';

    /** subject_type values written for each kind of record, old and new spellings. */
    private const PAYOUT_DETAILS = ['payout_details', OrganizationPayoutDetail::class];

    private const IDENTITY_DOCUMENTS = ['identity_document', OrganizationIdentityDocument::class];

    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return AuditLogResource::getAuthorizationResponse($action, $record);
    }

    public static function table(Table $table): Table
    {
        $in = fn (array $types): string => implode(', ', array_map(fn (string $type) => DB::getPdo()->quote($type), $types));

        return Listing::defaults($table, 'reads')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('user:id,name,email,platform_role')
                ->addSelect(['organization_name' => DB::table('organizations')
                    ->selectRaw('organizations.name')
                    ->whereRaw('organizations.id = coalesce(
                        (select d.organization_id from organization_payout_details d where d.id = sensitive_data_accesses.subject_id and sensitive_data_accesses.subject_type in ('.$in(self::PAYOUT_DETAILS).')),
                        (select i.organization_id from organization_identity_documents i where i.id = sensitive_data_accesses.subject_id and sensitive_data_accesses.subject_type in ('.$in(self::IDENTITY_DOCUMENTS).'))
                    )')
                    ->limit(1)]))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When (UTC)')
                    ->dateTime('j M Y, H:i:s')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Who')
                    ->placeholder('Someone no longer on the account')
                    ->description(fn (SensitiveDataAccess $record): ?string => $record->user?->platform_role !== null
                        ? 'myFiesta staff · '.$record->user->platform_role->label()
                        : ($record->user ? 'Organizer team' : null))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereIn(
                        'sensitive_data_accesses.user_id',
                        User::query()->where('name', 'ilike', '%'.$search.'%')->orWhere('email', 'ilike', '%'.$search.'%')->select('id'),
                    )),
                TextColumn::make('action')->badge()->color(fn (string $state): string => in_array($state, ['decrypted', 'exported'], true) ? 'warning' : 'gray'),
                TextColumn::make('subject_type')
                    ->label('What')
                    ->formatStateUsing(fn (string $state): string => self::kind($state)),
                TextColumn::make('organization_name')
                    ->label('Whose')
                    ->placeholder('Unknown'),
                TextColumn::make('ip_address')->label('From')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->options([
                    'viewed' => 'Viewed',
                    'decrypted' => 'Decrypted',
                    'exported' => 'Exported',
                    'write' => 'Written',
                ]),
                SelectFilter::make('kind')
                    ->label('What')
                    ->options(['payout' => 'Payout details', 'identity' => 'Identity documents'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'payout' => $query->whereIn('sensitive_data_accesses.subject_type', self::PAYOUT_DETAILS),
                        'identity' => $query->whereIn('sensitive_data_accesses.subject_type', self::IDENTITY_DOCUMENTS),
                        default => $query,
                    }),
                SelectFilter::make('user_id')
                    ->label('Who')
                    ->relationship('user', 'name')
                    ->searchable(),
                Listing::dateRange('occurred', 'occurred_at', 'When'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function kind(string $subjectType): string
    {
        return match (true) {
            in_array($subjectType, self::PAYOUT_DETAILS, true) => 'Payout details',
            in_array($subjectType, self::IDENTITY_DOCUMENTS, true) => 'Identity document',
            default => class_basename($subjectType),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSensitiveDataAccesses::route('/'),
        ];
    }
}
