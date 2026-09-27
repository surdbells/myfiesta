<?php

namespace App\Filament\Resources\Users\Actions;

use App\Filament\Support\Outcome;
use App\Models\User;
use App\Services\StaffSupport\AccountActions;
use App\Services\StaffSupport\StaffAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * What support can do to an account, as buttons.
 *
 * The same actions on the list and on the account's own page. Each is hidden
 * from a role that cannot use it and from accounts it cannot be used on — the
 * viewer's own, or a colleague's unless the viewer is an administrator — and
 * AccountActions refuses the same cases again if a hidden button is somehow
 * pressed.
 */
final class UserActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::passwordReset(),
            self::resendVerification(),
            self::signOutEverywhere(),
            self::deactivate(),
            self::reactivate(),
        ];
    }

    public static function passwordReset(): Action
    {
        return Action::make('sendPasswordReset')
            ->label('Send password reset link')
            ->icon(Heroicon::OutlinedKey)
            ->authorize(fn () => StaffAction::current(StaffAction::Resend))
            ->visible(fn (User $record) => ! $record->trashed() && self::mayActOn($record, StaffAction::Resend))
            ->requiresConfirmation()
            ->modalHeading('Send a password reset link?')
            ->modalDescription(fn (User $record) => 'Emails '.$record->email.' the same link the forgot-password screen sends. '
                .'You never see or choose the password. '
                .($record->isUnclaimed() ? 'This account has no password yet — the link is how its owner claims it.' : ''))
            ->modalSubmitActionLabel('Send link')
            ->action(fn (User $record, Action $action) => Outcome::run(
                $action,
                'Reset link sent',
                fn (User $staff) => app(AccountActions::class)->sendPasswordReset($record, $staff),
            ));
    }

    public static function resendVerification(): Action
    {
        return Action::make('resendVerification')
            ->label('Resend verification email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize(fn () => StaffAction::current(StaffAction::Resend))
            ->visible(fn (User $record) => ! $record->trashed()
                && $record->email_verified_at === null
                && self::mayActOn($record, StaffAction::Resend))
            ->requiresConfirmation()
            ->modalHeading('Resend the verification email?')
            ->modalDescription(fn (User $record) => 'Emails '.$record->email.' a link that proves they read it. '
                .'It shares the same hourly limit as the button they have themselves.')
            ->modalSubmitActionLabel('Send link')
            ->action(fn (User $record, Action $action) => Outcome::run(
                $action,
                'Verification email sent',
                fn (User $staff) => app(AccountActions::class)->resendVerification($record, $staff),
            ));
    }

    public static function signOutEverywhere(): Action
    {
        return Action::make('signOutEverywhere')
            ->label('Sign out everywhere')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('warning')
            ->authorize(fn () => StaffAction::current(StaffAction::SignOut))
            ->visible(fn (User $record) => ! $record->trashed() && self::mayActOn($record, StaffAction::SignOut))
            ->requiresConfirmation()
            ->modalHeading('Sign this account out everywhere?')
            ->modalDescription('Ends every app and browser session, every "remember me", and any door links this account handed to other phones. '
                .'Their password does not change. Use this when somebody thinks another person is in their account.')
            ->modalSubmitActionLabel('Sign out everywhere')
            ->action(fn (User $record, Action $action) => Outcome::run(
                $action,
                'Signed out everywhere',
                function (User $staff) use ($record) {
                    $ended = app(AccountActions::class)->signOutEverywhere($record, $staff);

                    return "{$ended['tokens']} app sessions, {$ended['sessions']} browser sessions and {$ended['door_passes']} door links ended.";
                },
            ));
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate account')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::Deactivate))
            ->visible(fn (User $record) => ! $record->trashed() && self::mayActOn($record, StaffAction::Deactivate))
            ->modalHeading(fn (User $record) => 'Deactivate '.$record->name.'?')
            ->modalDescription(fn (User $record) => self::deactivationWarning($record))
            ->modalSubmitActionLabel('Deactivate')
            ->schema([
                Textarea::make('reason')
                    ->label('Why?')
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000)
                    ->rows(3)
                    ->helperText('Kept in the audit trail for whoever reviews this account next. Not sent to them.'),
            ])
            ->action(fn (User $record, array $data, Action $action) => Outcome::run(
                $action,
                'Account deactivated',
                fn (User $staff) => app(AccountActions::class)->deactivate($record, $staff, (string) $data['reason']),
            ));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate account')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->authorize(fn () => StaffAction::current(StaffAction::Deactivate))
            ->visible(fn (User $record) => $record->trashed()
                && ! app(AccountActions::class)->wasErased($record)
                && self::mayActOn($record, StaffAction::Deactivate))
            ->modalHeading('Reactivate this account?')
            ->modalDescription('They can sign in again. Nothing that was ended when it was deactivated comes back: they sign in afresh.')
            ->modalSubmitActionLabel('Reactivate')
            ->schema([
                Textarea::make('note')
                    ->label('Note')
                    ->maxLength(1000)
                    ->rows(2),
            ])
            ->action(fn (User $record, array $data, Action $action) => Outcome::run(
                $action,
                'Account reactivated',
                fn (User $staff) => app(AccountActions::class)->reactivate($record, $staff, $data['note'] ?? null),
            ));
    }

    private static function mayActOn(User $record, StaffAction $action): bool
    {
        $staff = auth()->user();

        return $staff instanceof User && app(AccountActions::class)->mayActOn($record, $staff, $action);
    }

    private static function deactivationWarning(User $record): string
    {
        $owns = $record->organizations()
            ->wherePivot('role', 'owner')
            ->pluck('organizations.name');

        $warning = 'They are signed out everywhere and cannot sign in. Nothing they bought or sold is touched, and an administrator can reactivate the account.';

        if ($owns->isNotEmpty()) {
            $warning .= ' They own '.$owns->implode(', ').' — make sure somebody else there can run it.';
        }

        return $warning;
    }
}
