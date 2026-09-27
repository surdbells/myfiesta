<?php

namespace App\Filament\Resources\Staff\Tables;

use App\Enums\PlatformRole;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffChangeRefused;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record) => match (true) {
                        self::isYou($record) => 'You',
                        $record->trashed() => 'Account deactivated',
                        default => null,
                    }),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Address copied'),

                TextColumn::make('platform_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (PlatformRole $state) => $state->label())
                    ->color(fn (PlatformRole $state) => match ($state) {
                        PlatformRole::Admin => 'danger',
                        PlatformRole::Finance => 'success',
                        PlatformRole::Support => 'info',
                    })
                    ->sortable(),

                /*
                 * The sign-in code goes to this address, so an unverified one
                 * cannot get in at all; nor can a deactivated account, though
                 * reactivating it would let it back in with this role.
                 */
                IconColumn::make('email_verified')
                    ->label('Can sign in')
                    ->state(fn (User $record) => $record->email_verified_at !== null && ! $record->trashed())
                    ->boolean()
                    ->tooltip(fn (User $record) => match (true) {
                        $record->trashed() => 'The account is deactivated. Reactivating it would give this role back; revoke it if they have left.',
                        $record->email_verified_at === null => 'Their address is not verified, so no sign-in code can be sent.',
                        default => null,
                    }),

                /*
                 * The admin and the apps both record it. A staff member who
                 * has never signed in anywhere is worth a second look.
                 */
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Account since')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name or email')
            ->filters([
                SelectFilter::make('platform_role')
                    ->label('Role')
                    ->options(StaffResource::roleOptions())
                    ->multiple(),

                TernaryFilter::make('signed_in')
                    ->label('Signed in')
                    ->placeholder('Everyone')
                    ->trueLabel('Has signed in')
                    ->falseLabel('Never signed in')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('last_login_at'),
                        false: fn (Builder $query) => $query->whereNull('last_login_at'),
                        blank: fn (Builder $query) => $query,
                    ),

                Filter::make('last_signed_in')
                    ->label('Last sign-in between')
                    ->schema([
                        DatePicker::make('from')->label('Last signed in from'),
                        DatePicker::make('until')->label('Last signed in until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('last_login_at', '>=', Carbon::parse($date)->startOfDay()))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('last_login_at', '<=', Carbon::parse($date)->endOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Signed in since '.Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Last signed in by '.Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),

                // Off by default, so the list is the people who work here.
                TrashedFilter::make()
                    ->label('Deactivated accounts')
                    ->placeholder('Leave out')
                    ->trueLabel('Include')
                    ->falseLabel('Only deactivated'),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    // Nobody changes their own. Another administrator does,
                    // and is seen doing it. A deactivated account can only
                    // have its access revoked.
                    ->visible(fn (User $record) => ! self::isYou($record) && ! $record->trashed())
                    ->fillForm(fn (User $record) => ['platform_role' => $record->platform_role?->value])
                    ->schema([
                        Radio::make('platform_role')
                            ->label('Role')
                            ->options(StaffResource::roleOptions())
                            ->descriptions(StaffResource::roleDescriptions())
                            ->required(),
                    ])
                    ->modalHeading(fn (User $record) => 'Change '.$record->name.'’s role')
                    ->modalDescription('Takes effect on their next click. Recorded in the audit trail under your name.')
                    ->modalSubmitActionLabel('Change role')
                    ->action(function (User $record, array $data, Action $action): void {
                        $role = PlatformRole::from($data['platform_role']);

                        try {
                            app(StaffAccess::class)->changeRole($record, $role, auth()->user());
                        } catch (StaffChangeRefused $refused) {
                            Notification::make()
                                ->title('Role not changed')
                                ->body($refused->getMessage())
                                ->danger()
                                ->send();

                            $action->halt();
                        }

                        Notification::make()
                            ->title($record->name.' now has the '.$role->label().' role')
                            ->success()
                            ->send();
                    }),

                Action::make('revoke')
                    ->label('Revoke access')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (User $record) => ! self::isYou($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Revoke '.$record->name.'’s staff access?')
                    ->modalDescription(fn (User $record) => $record->trashed()
                        ? 'The account is deactivated, so they cannot sign in now; this takes the role off it, so reactivating the account later does not bring the admin back with it.'
                        : 'They are signed out of the admin everywhere, straight away, and cannot sign back in. Their myFiesta account, tickets and organizations are untouched, and access can be granted again later.')
                    ->modalSubmitActionLabel('Revoke access')
                    ->action(function (User $record): void {
                        try {
                            app(StaffAccess::class)->revoke($record, auth()->user());
                        } catch (StaffChangeRefused $refused) {
                            Notification::make()
                                ->title('Access not revoked')
                                ->body($refused->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($record->name.' no longer has staff access')
                            ->body('Signed out of the admin everywhere.')
                            ->success()
                            ->send();
                    }),
            ])
            // Changing several people's access at once is exactly the kind
            // of thing that should take several deliberate clicks.
            ->toolbarActions([])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading('No staff match')
            ->emptyStateDescription('Try a different search, or clear the filters.');
    }

    private static function isYou(User $record): bool
    {
        return $record->getKey() === auth()->id();
    }
}
