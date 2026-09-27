<?php

namespace App\Filament\Resources\Tickets\Actions;

use App\Filament\Support\Outcome;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StaffSupport\StaffAction;
use App\Services\StaffSupport\TicketActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * What staff can do to one ticket: send it again, stop it working, or move it
 * to somebody else.
 *
 * None of them shows the code. Resending and reissuing email it to the holder;
 * the person at the panel never needs to read it out.
 */
final class TicketRowActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::resend(),
            self::reissue(),
            self::void(),
        ];
    }

    public static function resend(): Action
    {
        return Action::make('resendTicket')
            ->label('Resend to holder')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize(fn () => StaffAction::current(StaffAction::Resend))
            ->visible(fn (Ticket $record) => $record->status === 'valid')
            ->requiresConfirmation()
            ->modalHeading('Resend this ticket?')
            ->modalDescription(fn (Ticket $record) => 'Emails the ticket to '.($record->owner_email ?: $record->order?->buyer_email ?: 'its holder').'.')
            ->modalSubmitActionLabel('Resend')
            ->action(fn (Ticket $record, Action $action) => Outcome::run(
                $action,
                'Ticket resent',
                fn (User $staff) => app(TicketActions::class)->resendTicket($record, $staff),
            ));
    }

    public static function void(): Action
    {
        return Action::make('voidTicket')
            ->label('Void')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::Void))
            ->visible(fn (Ticket $record) => $record->status === 'valid' && (int) $record->admitted_count === 0)
            ->modalHeading('Void this ticket?')
            ->modalDescription('It stops working at the door immediately. No money is returned — to give the buyer their money back, refund the ticket from its order instead, which voids it too.')
            ->modalSubmitActionLabel('Void ticket')
            ->schema([
                Textarea::make('reason')
                    ->label('Why?')
                    ->required()
                    ->minLength(5)
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(fn (Ticket $record, array $data, Action $action) => Outcome::run(
                $action,
                'Ticket voided',
                function (User $staff) use ($record, $data) {
                    app(TicketActions::class)->void($record, $staff, (string) $data['reason']);
                },
            ));
    }

    public static function reissue(): Action
    {
        return Action::make('reissueTicket')
            ->label('Reissue to another email')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->authorize(fn () => StaffAction::current(StaffAction::Reissue))
            ->visible(fn (Ticket $record) => $record->status === 'valid' && (int) $record->admitted_count === 0)
            ->modalHeading('Move this ticket to somebody else')
            ->modalDescription('The ticket is recorded as transferred and emailed to the new address. '
                .'Only do this when the buyer has asked, from the address they bought with. '
                .'It leaves the buyer\'s order link and their account, and resending the order no longer includes it.')
            ->modalSubmitActionLabel('Reissue')
            ->schema([
                TextInput::make('email')
                    ->label('New holder\'s email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->label('New holder\'s name')
                    ->required()
                    ->maxLength(120),
                Toggle::make('new_code')
                    ->label('Issue a new code')
                    ->helperText('The old code stops working at the door. Leave on unless the holder already has the code and cannot receive email.')
                    ->default(true),
                Textarea::make('reason')
                    ->label('Why?')
                    ->required()
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(fn (Ticket $record, array $data, Action $action) => Outcome::run(
                $action,
                'Ticket reissued',
                function (User $staff) use ($record, $data) {
                    app(TicketActions::class)->reissue(
                        $record,
                        $staff,
                        (string) $data['email'],
                        (string) $data['name'],
                        (bool) ($data['new_code'] ?? true),
                        $data['reason'] ?? null,
                    );

                    return 'Emailed to '.strtolower(trim((string) $data['email'])).'.';
                },
            ));
    }
}
