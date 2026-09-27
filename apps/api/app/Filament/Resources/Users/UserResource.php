<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Users\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Support\ReadOnlyForStaff;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * People: the first screen support opens when somebody writes in.
 *
 * Find them by whatever they gave — name, address, phone — and see in one
 * place what they bought, what they hold, which organizations they run, and
 * what has been done to their account. Nothing is edited here; the actions on
 * the account are named, gated by role, and written to the audit trail.
 */
class UserResource extends Resource
{
    use ReadOnlyForStaff;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'People';

    protected static ?string $modelLabel = 'account';

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    /** Deactivated accounts included, so the list can show them when asked and their page still opens. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * The account an order or ticket points at, as its address, deactivated
     * accounts included and marked.
     *
     * Order::user() and Ticket::owner() hide soft-deleted accounts, so read
     * through them a deactivated buyer looks like a guest. That is the wrong
     * thing to tell support at exactly the moment they need the link.
     */
    public static function accountLabel(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $account = User::withTrashed()->whereKey($userId)->first(['id', 'email', 'deleted_at']);

        if ($account === null) {
            return null;
        }

        return $account->trashed() ? $account->email.' (deactivated)' : $account->email;
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Email' => $record->email,
            'Staff' => $record->platform_role?->label(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
            TicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
