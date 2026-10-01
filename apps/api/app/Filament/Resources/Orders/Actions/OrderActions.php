<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Filament\Support\Listing;
use App\Filament\Support\Outcome;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payments\PayLater;
use App\Services\Refunds\RefundService;
use App\Services\StaffSupport\MaskedCode;
use App\Services\StaffSupport\StaffAction;
use App\Services\StaffSupport\StaffActionRefused;
use App\Services\StaffSupport\TicketActions;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * What staff can do to an order.
 *
 * Refunds go through RefundService — the same arithmetic, gateway call, ledger
 * entries and audit record an organizer's refund gets — never around it. The
 * panel only chooses which tickets. The one exception is money support sent
 * back outside the processor, which goes through RefundService's
 * made-elsewhere path instead (recordReturnedOutside).
 */
final class OrderActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::refund(),
            self::recordReturnedOutside(),
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
            ->visible(fn (Order $record) => self::refundable($record) && ! self::pastLendersWindow($record))
            ->modalHeading(fn (Order $record) => 'Refund order '.$record->reference.' to '.($record->buyer_email ?? 'its buyer').'?')
            ->modalDescription(fn (Order $record) => 'Paid '.$record->total->format().'. The money goes back to the card or account it came from, through '
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
            ->modalHeading(fn (Collection $records) => 'Refund '.$records->count().' selected '.($records->count() === 1 ? 'ticket' : 'tickets').'?')
            ->modalDescription('Their share of what was paid goes back to the buyer through the payment processor, and they stop working at the door straight away. A refund cannot be undone.')
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

    /**
     * Money support sent back outside Stripe, written down.
     *
     * For an order paid with Klarna or Affirm longer ago than the lender
     * takes refunds back for, where Refund is not offered: Stripe would
     * refuse it. Support sends the buyer their money another way (an
     * e-Transfer, say), then records it here through the same made-elsewhere
     * path as a refund made in Stripe's dashboard. The order shows the
     * refund, the organizer's balance stops counting the money, they are
     * emailed, and when it covers everything left the tickets stop working.
     */
    public static function recordReturnedOutside(): Action
    {
        return Action::make('recordReturnedOutside')
            ->label('Record a refund made outside Stripe')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::Refund))
            ->visible(fn (Order $record) => self::refundable($record) && self::pastLendersWindow($record))
            ->modalHeading(fn (Order $record) => 'Record money returned to '.($record->buyer_email ?? 'the buyer').' outside Stripe?')
            ->modalDescription(fn (Order $record) => self::lenderClosed($record)
                .' Send the buyer their money another way first, then record it here. '
                .self::left($record)->format().' is left on order '.$record->reference.'. Recording all of it stops the order\'s tickets working; '
                .'recording part leaves them working, and the organizer is asked which tickets it was for. '
                .'Either way it comes off the organizer\'s balance, they are emailed, and it cannot be undone. No money moves from here.')
            ->modalSubmitActionLabel('Record the refund')
            ->fillForm(fn (Order $record) => ['amount' => number_format(self::left($record)->amount / 100, 2, '.', '')])
            ->schema(fn (Order $record) => [
                TextInput::make('amount')
                    ->label('Amount sent to the buyer')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(self::left($record)->amount / 100)
                    ->step('0.01')
                    ->prefix(Listing::prefix($record->currency))
                    ->helperText('Up to '.self::left($record)->format().', what is left on the order.'),

                TextInput::make('reference')
                    ->label('Reference')
                    ->required()
                    ->maxLength(120)
                    ->helperText('The e-Transfer or bank reference for the money you sent, so it can be matched to our statement.'),

                Textarea::make('note')
                    ->label('Note')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->helperText('Who asked and why. Kept with the refund and in the audit trail.'),

                Checkbox::make('sent')
                    ->label('The money has been sent to the buyer')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Record it once the money has been sent.']),
            ])
            ->action(fn (Order $record, array $data, Action $action) => Outcome::run(
                $action,
                'Refund recorded',
                fn (User $staff) => self::recordOutside(
                    $record,
                    $staff,
                    (int) round((float) $data['amount'] * 100),
                    trim((string) $data['reference']),
                    trim((string) $data['note']),
                ),
            ));
    }

    public static function resendTickets(): Action
    {
        return Action::make('resendTickets')
            ->label('Resend tickets email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize(fn () => StaffAction::current(StaffAction::Resend))
            ->visible(fn (Order $record) => in_array($record->status, ['paid', 'partially_refunded'], true) && filled($record->buyer_email))
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => 'Resend the tickets email for '.$record->reference.'?')
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

    /**
     * Paid with Klarna or Affirm longer ago than the lender takes refunds
     * back for, so a refund through Stripe would be refused.
     */
    public static function pastLendersWindow(Order $order): bool
    {
        return app(PayLater::class)->refundRefusal($order) !== null;
    }

    /** What is left on the order to give back: paid less refunds that went through or may have. */
    private static function left(Order $order): Money
    {
        $counted = (int) $order->refunds()->whereIn('status', ['pending', 'succeeded'])->sum('amount');

        return new Money(max(0, $order->total_amount - $counted), $order->currency);
    }

    /** "Klarna takes money back only within 180 days of a payment, and this one was on 2 March 2026." */
    private static function lenderClosed(Order $order): string
    {
        $payLater = app(PayLater::class);
        $method = (string) $payLater->methodOf($order);
        $name = $payLater->name($method);
        $days = (int) config("payments.pay_later.providers.{$method}.refund_days");
        $paid = $order->paid_at?->copy()->timezone($order->event->timezone ?? 'UTC')->format('j F Y');

        return "{$name} takes money back only within {$days} days of a payment, and this order was paid with {$name} on {$paid}, so Stripe cannot refund it.";
    }

    private static function recordOutside(Order $order, User $staff, int $amount, string $reference, string $note): string
    {
        StaffAction::Refund->authorize($staff);

        if (! self::pastLendersWindow($order)) {
            throw StaffActionRefused::because('This order can still be refunded through Stripe. Use Refund instead, so the money goes back the way it came.');
        }

        $left = self::left($order);

        if ($amount <= 0 || $amount > $left->amount) {
            throw StaffActionRefused::because('Record between '.(new Money(1, $order->currency))->format().' and '.$left->format().', what is left on the order.');
        }

        $refund = app(RefundService::class)->recordMadeElsewhere(
            $order,
            $amount,
            tellTheOrganizer: true,
            recordedBy: $staff,
            how: "Returned by myFiesta support outside Stripe, ref. {$reference}: {$note}",
        );

        if ($refund === null) {
            throw StaffActionRefused::because('Nothing is left to refund on this order, so nothing was recorded.');
        }

        return Listing::format((int) $refund->amount, $refund->currency).' is recorded as returned to the buyer. '
            .($order->fresh()?->status === 'refunded'
                ? 'The order\'s tickets no longer get in.'
                : 'Its tickets still work: the organizer has been asked which ones it was for.');
    }

    /** @param  list<string>|null  $ticketIds */
    private static function sendRefund(Order $order, User $staff, ?array $ticketIds, string $reason): string|Notification
    {
        StaffAction::Refund->authorize($staff);

        if ($ticketIds === []) {
            throw StaffActionRefused::because('Choose at least one ticket.');
        }

        // Said for staff: the console's wording sends the organizer to
        // support, which is who is reading this.
        if (self::pastLendersWindow($order)) {
            throw StaffActionRefused::because(self::lenderClosed($order)
                .' Send the buyer their money another way, then use "Record a refund made outside Stripe" on the order. Nothing has been refunded and the tickets still work.');
        }

        $refund = app(RefundService::class)->refund($order, $ticketIds, $staff, trim($reason));

        return self::saidAbout($order->fresh(), $refund);
    }

    /**
     * What the person who pressed Refund is told, true to where it stands.
     *
     * Succeeded: the processor took it, the money is on its way and the
     * tickets it was for have stopped. Pending: sent, and no answer yet. It
     * may well have paid the buyer, so it is neither a success nor "nothing
     * happened" — and pressing Refund again would not help: those tickets are
     * held by this refund until the processor is asked about it again.
     * Failed: turned down, or never sent, and nothing moved.
     *
     * Except that a refusal can be the processor saying the money has gone
     * back already, refunded in its own dashboard, and RefundService asks
     * about that straight away and records what it finds (afterRefusal).
     * Then the buyer does have their money, the order says so, and "nothing
     * moved" would be the opposite of true.
     *
     * Public so the words can be checked without a processor to refuse.
     */
    public static function saidAbout(Order $order, Refund $refund): string|Notification
    {
        $processor = self::processorName($order);
        $why = rtrim((string) ($refund->failure_reason ?: 'no reason given'), '. ');

        if ($refund->status === 'succeeded') {
            $tickets = $refund->tickets()->count();

            return Listing::format((int) $refund->amount, $refund->currency).' is on its way back to the buyer through '.$processor.'. '
                .($tickets === 1 ? 'The ticket it was for no longer gets in.' : "The {$tickets} tickets it was for no longer get in.");
        }

        if ($refund->status === 'pending') {
            return Notification::make()
                ->title('Refund sent, not yet confirmed')
                ->body("Sent to {$processor}, which has not answered yet".($refund->failure_reason ? " ({$why})" : '').'. '
                    .'The money may already be on its way. It is asked about again automatically, and the order shows the refund once '.$processor.' answers. '
                    .'The tickets keep working until then. Do not refund them again.')
                ->warning()
                ->persistent();
        }

        $foundElsewhere = (int) Refund::query()
            ->where('order_id', $order->id)
            ->where('source', Refund::FROM_PROCESSOR)
            ->where('status', 'succeeded')
            ->where('created_at', '>=', $refund->created_at)
            ->sum('amount');

        if ($foundElsewhere > 0) {
            return Notification::make()
                ->title('Refund turned down: the money had already gone back')
                ->body("{$processor} turned this refund down ({$why}). It had already refunded "
                    .Listing::format($foundElsewhere, $refund->currency).' of this payment, made in its own dashboard, and that is now recorded on the order. '
                    .($order->status === 'refunded'
                        ? 'Nothing is left to refund, and the tickets no longer get in.'
                        : 'The tickets still work: the organizer has been asked which ones it was for. Do not refund them again.'))
                ->warning()
                ->persistent();
        }

        throw StaffActionRefused::because("The refund did not go through: {$why}. No money moved and the tickets still work. The attempt is recorded on the order.");
    }

    private static function processorName(Order $order): string
    {
        return match ($order->gateway) {
            'stripe' => 'Stripe',
            'paystack' => 'Paystack',
            default => 'the payment processor',
        };
    }
}
