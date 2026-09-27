<?php

namespace App\Filament\Resources\Organizations\Actions;

use App\Filament\Support\Outcome;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\Suspension;
use App\Services\StaffSupport\StaffAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * Suspending an organization, and lifting it.
 *
 * Administrators only; support and finance see that an organization is
 * suspended, and why, but not the buttons. Suspension checks the role again,
 * so hiding them is not the guard. Both are recorded in the audit trail and
 * email the organization's owners.
 */
final class OrganizationActions
{
    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::Suspend))
            ->visible(fn (Organization $record) => ! $record->isSuspended() && ! $record->trashed())
            ->modalIcon(Heroicon::OutlinedNoSymbol)
            ->modalHeading(fn (Organization $record) => 'Suspend '.$record->name.'?')
            ->modalDescription('Every event it has on sale comes off sale, nothing can be sold — online, at the door or through resale — and nothing can be published. '
                .'Payouts freeze: new requests are refused and waiting ones are held, not rejected. '
                .'Tickets already sold still work at the door, and refunds can still be made. '
                .'The owners are emailed. Lifting the suspension puts back on sale exactly the events it took off, if they have not happened yet.')
            ->modalSubmitActionLabel('Suspend')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(Suspension::MIN_REASON)
                    ->maxLength(1000)
                    ->rows(4)
                    ->helperText('Kept on the record and in the audit trail. Say what happened and what would lift it.'),
                Toggle::make('share_reason')
                    ->label('Show this reason to the organization')
                    ->default(false)
                    ->helperText('In the console’s banner and in the email to the owners. Leave off when the reason is for us alone — the owners are still told they are suspended and how to reach support.'),
            ])
            ->action(fn (Organization $record, array $data, Action $action) => Outcome::run(
                $action,
                'Organization suspended',
                function (User $staff) use ($record, $data) {
                    $done = app(Suspension::class)->suspend(
                        $record,
                        $staff,
                        (string) $data['reason'],
                        (bool) ($data['share_reason'] ?? false),
                    );

                    return self::summary([
                        self::count(count($done['events']), 'event', 'events').' taken off sale',
                        self::count(count($done['held']), 'payout request', 'payout requests').' held',
                        $done['told'] === 0
                            ? 'no owner could be emailed — tell them another way'
                            : self::count($done['told'], 'owner', 'owners').' emailed',
                    ]);
                },
            ));
    }

    public static function unsuspend(): Action
    {
        return Action::make('unsuspend')
            ->label('Lift suspension')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->authorize(fn () => StaffAction::current(StaffAction::Suspend))
            ->visible(fn (Organization $record) => $record->isSuspended() && ! $record->trashed())
            ->modalHeading(fn (Organization $record) => 'Lift the suspension on '.$record->name.'?')
            ->modalDescription('Sales and publishing are allowed again. The events the suspension took off sale go back on sale unless they have started, or were cancelled, deleted or taken down since; '
                .'those stay drafts. Held payout requests go back to waiting. The owners are emailed.')
            ->modalSubmitActionLabel('Lift suspension')
            ->schema([
                Textarea::make('note')
                    ->label('Note')
                    ->maxLength(1000)
                    ->rows(2)
                    ->helperText('For the audit trail: what changed, or who decided.'),
            ])
            ->action(fn (Organization $record, array $data, Action $action) => Outcome::run(
                $action,
                'Suspension lifted',
                function (User $staff) use ($record, $data) {
                    $done = app(Suspension::class)->unsuspend($record, $staff, $data['note'] ?? null);

                    if (! $done['lifted']) {
                        return 'It was not suspended.';
                    }

                    return self::summary([
                        self::count(count($done['republished']), 'event', 'events').' back on sale',
                        count($done['left']) > 0 ? self::count(count($done['left']), 'event', 'events').' left as drafts' : null,
                        self::count(count($done['released']), 'payout request', 'payout requests').' released',
                        self::count($done['told'], 'owner', 'owners').' emailed',
                    ]);
                },
            ));
    }

    /** @param  list<string|null>  $parts */
    private static function summary(array $parts): string
    {
        return ucfirst(implode(', ', array_filter($parts))).'.';
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
