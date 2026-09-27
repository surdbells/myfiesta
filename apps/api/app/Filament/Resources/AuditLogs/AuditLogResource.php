<?php

namespace App\Filament\Resources\AuditLogs;

use App\Enums\PlatformRole;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\AuditLogs\Schemas\AuditLogInfolist;
use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Everything anybody did, platform-wide, read-only.
 *
 * The trail is append-only in the database — a trigger refuses UPDATE and
 * DELETE on audit_logs — and this screen matches it: it lists and shows, and
 * refuses every other ability outright, whatever the role. There is no edit,
 * no delete, no bulk action and no export here, ever.
 *
 * Administrators and finance may read it. Support answer questions about one
 * record at a time, and see that record's own trail on its page.
 */
class AuditLogResource extends Resource
{
    public const ROLES = [PlatformRole::Admin, PlatformRole::Finance];

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'audit-log';

    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        $allowed = $user instanceof User
            && $user->deleted_at === null
            && $user->hasPlatformRole(...self::ROLES)
            && in_array($action, ['viewAny', 'view'], true);

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function table(Table $table): Table
    {
        return AuditLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditLogInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
