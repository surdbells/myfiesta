<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Filament\Support\Listing;
use App\Filament\Support\Outcome;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Refunds\RefundService;
use App\Services\StaffSupport\MaskedCode;
use App\Services\StaffSupport\StaffAction;
use App\Services\StaffSupport\StaffActionRefused;
use App\Services\StaffSupport\TicketActions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * What staff can do to an order.
 *
 * Refunds go through RefundService — the same arithmetic, gateway call, ledger
 * entries and audit record an organizer's refund gets — never around it. The
 * panel only chooses which tickets.
 */
final class OrderActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::refund(),
            self::resendTickets(),
            self::addNote(),
        ];
    }

    public static function refund(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::Refund))
            ->visible(fn (Order $record) => self::refundable($record))
            ->modalHeading(fn (Order $record) => 'Refund order '.$record->reference)
            ->modalDescription(fn (Order $record) => 'The money goes back to the card or account it came from, through '
                .ucfirst((string) $record->gateway).'. Refunded tickets stop working at the door straight away. '
                .'The amount is worked out from the tickets you choose — it cannot be typed.')
            ->modalSubmitActionLabel('Send the refund')
            ->schema(fn (Order $record) => [
                Radio::make('scope')
                    ->label('What to refund')
                    ->options([
                        'all' => 'Everything still refundable on this order',
                        'some' => 'Only the tickets I choose',
                    ])
                    ->default('all')
                    ->required()
                    ->live(),

                CheckboxList::make('tickets')
                    ->label('Tickets')
                    ->options(self::refundableTicketOptions($record))
                    ->visible(fn (Get $get) => $get('scope') === 'some')
                    ->required(fn (Get $get) => $get('scope') === 'some')
                    ->helperText('Codes are masked. A checked-in ticket can still be refunded — that is a decision you are allowed to make.'),

                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->helperText('Kept with the refund and in the audit trail.'),
            ])
            ->action(fn (Order $record, array $data, Action $action) => Outcome::run(
                $action,
                'Refund sent',
                fn (User $staff) => self::sendRefund(
                    $record,
                    $staff,
                    ($data['scope'] ?? 'all') === 'some' ? array_values($data['tickets'] ?? []) : null,
                    (string) $data['reason'],
                ),
            ));
    }

    /** The same refund, from tickets ticked in the order's ticket list. */
    public static function refundSelectedTickets(): BulkAction
    {
        return BulkAction::make('refundSelected')
            ->label('Refund selected tickets')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::Refund))
            ->modalHeading('Refund the selected tickets?')
            ->modalDescription('Their share of what was paid goes back through the payment processor, and they stop working at the door.')
            ->modalSubmitActionLabel('Send the refund')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(2),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, BulkAction $action) {
                // Read whole, not through the ticket's relation: the list
                // loads a trimmed order for display, and a refund needs the
                // processor and the amounts.
                $order = Order::query()->find($records->first()?->order_id);

                Outcome::run($action, 'Refund sent', function (User $staff) use ($records, $order, $data) {
                    if ($order === null || $records->contains(fn (Ticket $ticket) => $ticket->order_id !== $order->id)) {
                        throw StaffActionRefused::because('Choose tickets from one order at a time.');
                    }

                    return self::sendRefund($order, $staff, $records->modelKeys(), (string) $data['reason']);
                });
            });
    }

    public static function resendTickets(): Action
    {
        return Action::make('resendTickets')
            ->label('Resend tickets email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize(fn () => StaffAction::current(StaffAction::Resend))
            ->visible(fn (Order $record) => in_array($record->status, ['paid', 'partially_refunded'], true) && filled($record->buyer_email))
            ->requiresConfirmation()
            ->modalHeading('Resend the tickets email?')
            ->modalDescription(fn (Order $record) => 'Sends the buyer\'s confirmation again to '.$record->buyer_email.', with the same link to their tickets. '
                .'It lists only the tickets that still work and are still theirs. A ticket moved to somebody else is resent from the Tickets screen.')
            ->modalSubmitActionLabel('Resend')
            ->action(fn (Order $record, Action $action) => Outcome::run(
                $action,
                'Tickets email resent',
                fn (User $staff) => app(TicketActions::class)->resendOrder($record, $staff),
            ));
    }

    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label('Add a note')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->authorize(fn () => StaffAction::current(StaffAction::Note))
            ->modalHeading(fn (Order $record) => 'Note on order '.$record->reference)
            ->modalDescription(fn (Order $record) => $record->soldAtDoor()
                ? 'Sold at the door. Record what was said about the money or the people it admitted — the next person to open this order will read it.'
                : 'For the next person who opens this order. Notes are kept in the audit trail and cannot be edited.')
            ->modalSubmitActionLabel('Save note')
            ->schema([
                Textarea::make('note')->label('Note')->required()->minLength(3)->maxLength(2000)->rows(4),
            ])
            ->action(fn (Order $record, array $data, Action $action) => Outcome::run(
                $action,
                'Note saved',
                fn (User $staff) => app(TicketActions::class)->addOrderNote($record, $staff, (string) $data['note']),
            ));
    }

    /**
     * Paid through a processor, and something left on it to refund.
     *
     * A door sale is not refunded here: the money went into the organizer's
     * own tin or terminal, and no processor can send back cash we never held.
     */
    public static function refundable(Order $order): bool
    {
        return in_array($order->status, ['paid', 'partially_refunded'], true)
            && $order->total_amount > 0
            && $order->gateway !== null;
    }

    /** @return array<string, string> */
    public static function refundableTicketOptions(Order $order): array
    {
        return $order->tickets()
            ->with('ticketType:id,name')
            ->whereNotIn('status', ['refunded', 'void'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Ticket $ticket) => [
                $ticket->id => collect([
                    $ticket->ticketType?->name ?? 'Ticket',
                    $ticket->holder_name,
                    MaskedCode::of($ticket->code),
                    $ticket->status === 'valid' ? null : str_replace('_', ' ', $ticket->status),
                ])->filter()->implode(' · '),
            ])
            ->all();
    }

    /** @param  list<string>|null  $ticketIds */
    private static function sendRefund(Order $order, User $staff, ?array $ticketIds, string $reason): string|Notification
    {
        StaffAction::Refund->authorize($staff);

        if ($ticketIds === []) {
            throw StaffActionRefused::because('Choose at least one ticket.');
        }

        $refund = app(RefundService::class)->refund($order, $ticketIds, $staff, trim($reason));

        // Sent, and the processor did not answer. It may well have paid the
        // buyer, so this is neither a success nor "nothing happened" — and
        // pressing Refund again would not help: those tickets are held by
        // this refund until the processor is asked about it again.
        if ($refund->status === 'pending') {
            return Notification::make()
                ->title('Refund sent, not yet confirmed')
                ->body('The payment processor has not answered yet. It is asked again automatically, and the order shows the refund once it does. '
                    .'The tickets keep working until then. Do not refund them again.')
                ->warning()
                ->persistent();
        }

        if ($refund->status !== 'succeeded') {
            throw StaffActionRefused::because('The payment processor did not return the money: '
                .rtrim((string) ($refund->failure_reason ?: 'no reason given'), '. ').'. Nothing was voided; the attempt is recorded on the order.');
        }

        return Listing::format((int) $refund->amount, $refund->currency).' is on its way back to the buyer.';
    }
}
