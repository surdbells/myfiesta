<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\PlatformRole;
use App\Filament\Resources\Users\Actions\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Listing;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Everybody with an account: buyers, organizers and staff in one list, because
 * they are one table and very often one person.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'accounts')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['organizations', 'orders', 'tickets'])
                ->withMax('tokens', 'last_used_at'))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Name, email or phone')
            ->columns([
                TextColumn::make('name')
                    ->label('Person')
                    ->searchable(['name', 'email', 'phone'])
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (User $record) => $record->email),

                TextColumn::make('phone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('platform_role')
                    ->label('Staff')
                    ->badge()
                    ->formatStateUsing(fn (?PlatformRole $state) => $state?->label())
                    ->color(fn (?PlatformRole $state) => match ($state) {
                        PlatformRole::Admin => 'danger',
                        PlatformRole::Finance => 'warning',
                        PlatformRole::Support => 'info',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('organizations_count')
                    ->label('Orgs')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('tickets_count')
                    ->label('Tickets held')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->state(fn (User $record) => $record->email_verified_at !== null)
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Account')
                    ->badge()
                    ->state(fn (User $record) => match (true) {
                        str_ends_with(strtolower($record->email), '@erased.invalid') => 'Erased',
                        $record->trashed() => 'Deactivated',
                        $record->isUnclaimed() => 'Guest only',
                        default => 'Active',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Active' => 'success',
                        'Guest only' => 'gray',
                        default => 'danger',
                    }),

                // The later of a sign-in and the last time any of its app
                // sessions was used: an app stays signed in for weeks without
                // signing in again.
                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->state(fn (User $record) => self::lastSeen($record))
                    ->since()
                    ->placeholder('Never')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw(
                        'greatest(users.last_login_at, (select max(t.last_used_at) from personal_access_tokens t where t.tokenable_type = ? and t.tokenable_id = users.id)) '
                        .($direction === 'asc' ? 'asc' : 'desc').' nulls last',
                        [User::class],
                    )),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('j M Y')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('has_organizations')
                    ->label('Organizer')
                    ->trueLabel('Member of an organization')
                    ->falseLabel('Not a member of any')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('organizations'),
                        false: fn (Builder $query) => $query->whereDoesntHave('organizations'),
                    ),

                TernaryFilter::make('staff')
                    ->label('Platform staff')
                    ->trueLabel('Staff')
                    ->falseLabel('Not staff')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('platform_role'),
                        false: fn (Builder $query) => $query->whereNull('platform_role'),
                    ),

                SelectFilter::make('platform_role')
                    ->label('Staff role')
                    ->multiple()
                    ->options(collect(PlatformRole::cases())->mapWithKeys(fn (PlatformRole $role) => [$role->value => $role->label()])->all()),

                TernaryFilter::make('verified')
                    ->label('Email verified')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('email_verified_at'),
                    ),

                TernaryFilter::make('claimed')
                    ->label('Has a password')
                    ->trueLabel('Has signed up')
                    ->falseLabel('Guest checkout only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('password'),
                        false: fn (Builder $query) => $query->whereNull('password'),
                    ),

                Listing::dateRange('created', 'created_at', 'Joined'),

                TrashedFilter::make()
                    ->label('Deactivated')
                    ->placeholder('Active accounts')
                    ->trueLabel('Active and deactivated')
                    ->falseLabel('Deactivated only'),
            ])
            ->recordUrl(fn (User $record) => UserResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(UserActions::all()),
            ])
            ->toolbarActions([]);
    }

    public static function lastSeen(User $record): ?Carbon
    {
        $token = $record->tokens_max_last_used_at ?? null;

        $candidates = array_filter([
            $record->last_login_at,
            $token ? Carbon::parse($token) : null,
        ]);

        return $candidates === [] ? null : max($candidates);
    }
}
