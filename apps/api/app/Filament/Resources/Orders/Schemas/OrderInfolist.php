<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AuditTrail;
use App\Filament\Support\Listing;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\OrderLine;
use App\Models\PaymentEvidence;
use App\Models\Refund;
use App\Services\Checkout\TaxLine;
use App\Services\Payments\PayLater;
use App\Services\Payments\PaymentMethods;
use App\Services\Receipts\Receipt;
use App\Services\Settings\SellerOfRecord;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One order, everything support is asked about it.
 *
 * Who bought it and how, what was charged line by line, the payment, every
 * refund attempt (failed ones too — they are what people ring about), any
 * chargeback, what the buyer answered, and the trail. The tickets are the
 * table beneath, with their codes masked.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Section::make('Order')
                        ->columnSpan(['lg' => 2])
                        ->columns(2)
                        ->schema([
                            TextEntry::make('reference')->copyable()->fontFamily('mono')->weight('semibold'),
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => OrdersTable::STATUSES[$state] ?? $state)
                                ->color(fn (string $state) => match ($state) {
                                    'paid' => 'success',
                                    'pending' => 'warning',
                                    'partially_refunded', 'refunded' => 'info',
                                    default => 'danger',
                                }),
                            TextEntry::make('event.title')
                                ->label('Event')
                                ->url(fn (Order $record) => $record->event ? EventResource::getUrl('view', ['record' => $record->event]) : null)
                                ->helperText(fn (Order $record) => $record->event?->starts_at?->timezone($record->event->timezone)->format('D j M Y, g:ia')),
                            TextEntry::make('organization.name')->label('Organizer'),
                            TextEntry::make('buyer_name')->label('Buyer'),
                            TextEntry::make('buyer_email')
                                ->label('Buyer email')
                                ->copyable()
                                ->placeholder('None — sold at the door'),
                            TextEntry::make('buyer_phone')->label('Buyer phone')->placeholder('—'),
                            TextEntry::make('account')
                                ->label('Account')
                                ->state(fn (Order $record) => UserResource::accountLabel($record->user_id))
                                ->url(fn (Order $record) => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null)
                                ->placeholder('Guest checkout'),
                            TextEntry::make('channel')
                                ->label('Sold')
                                ->formatStateUsing(fn (Order $record) => $record->soldAtDoor()
                                    ? 'At the door, '.($record->payment_method ?? 'unknown').' — by '.($record->soldBy?->name ?? 'door staff')
                                    : 'Online'.($record->embedded ? ', from an embedded checkout' : '')),
                            TextEntry::make('created_at')->label('Placed')->dateTime('j M Y, H:i:s'),
                            TextEntry::make('paid_at')->label('Paid')->dateTime('j M Y, H:i:s')->placeholder('Not paid'),
                            TextEntry::make('refunded_at')->label('First refunded')->dateTime('j M Y, H:i:s')->placeholder('—'),
                        ]),

                    Section::make('Money')
                        ->description('In the currency it was paid in.')
                        ->columnSpan(['lg' => 1])
                        ->schema([
                            self::money('subtotal_amount', 'Subtotal'),
                            self::money('discount_amount', 'Discount'),
                            self::money('tax_amount', 'Tax')
                                ->helperText(fn (Order $record) => $record->tax_inclusive ? 'Included in the prices' : 'Added at checkout'),
                            self::money('service_charge_amount', 'Service charge')
                                // The figure is what the buyer paid for it, its
                                // tax inside, so the column still adds up to the
                                // total. How much of it is tax is said beneath.
                                ->helperText(fn (Order $record) => $record->service_charge_tax_amount > 0
                                    ? 'Includes '.Listing::format((int) $record->service_charge_tax_amount, $record->currency).' of tax'
                                    : null),
                            self::money('total_amount', 'Total charged')->weight('semibold'),
                            self::money('net_revenue_amount', 'Organizer earned'),
                            // Each tax on its own, the way the buyer's receipt
                            // shows it. A buyer asking why Quebec charged them
                            // twice is asking about GST and QST, and "Tax" above
                            // is the two added together.
                            TextEntry::make('each_tax')
                                ->label('Each tax')
                                ->state(fn (Order $record) => self::taxLines($record))
                                ->listWithLineBreaks()
                                ->placeholder('No tax on this order'),
                            TextEntry::make('seller_of_record')
                                ->label('Sold by')
                                ->inlineLabel()
                                ->alignEnd()
                                ->state(fn (Order $record) => (SellerOfRecord::tryFrom((string) $record->seller_of_record) ?? SellerOfRecord::Organizer)->label())
                                ->helperText('In law, and so who files the tax on the tickets'),
                            self::money('gateway_fee_amount', 'Processor fee')->placeholder('Not settled yet')
                                ->helperText(fn (Order $record) => self::feeShared($record)),
                            TextEntry::make('refunded_total')
                                ->label('Refunded so far')
                                ->state(fn (Order $record) => Listing::format(
                                    (int) $record->refunds()->where('status', 'succeeded')->sum('amount'),
                                    $record->currency,
                                )),
                        ]),
                ]),

            Section::make('Payment')
                ->columns(4)
                ->collapsible()
                ->schema([
                    TextEntry::make('gateway')
                        ->label('Processor')
                        ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : null)
                        ->placeholder('None — free, or paid at the door'),
                    // How the buyer paid on the processor's page, in its own
                    // word, read once the processor has been asked
                    // (ProcessorEvidence). Klarna and Affirm are paying later,
                    // and say so: a refund after their window is refused.
                    TextEntry::make('paid_with')
                        ->label('Paid with')
                        ->state(fn (Order $record) => PaymentMethods::label(app(PayLater::class)->methodOf($record)))
                        // A sale with no processor never will be known: the
                        // Processor entry above already says why.
                        ->placeholder(fn (Order $record) => $record->gateway === null ? '—' : 'Not known yet'),
                    TextEntry::make('gateway_reference')->label('Processor reference')->copyable()->placeholder('—'),
                    TextEntry::make('gateway_payment_reference')->label('Payment reference')->copyable()->placeholder('—'),
                    TextEntry::make('code.code')->label('Discount code')->placeholder('None'),
                ]),

            Section::make('What was bought')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('purchase_lines')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => $record->lines()
                            ->orderBy('created_at')
                            ->get()
                            ->map(fn (OrderLine $line) => [
                                'name' => $line->name,
                                'kind' => $line->isTicket() ? 'Ticket' : 'Add-on',
                                'quantity' => (string) $line->quantity,
                                'unit' => Listing::format((int) $line->unit_price_amount, $record->currency),
                                'discount' => $line->discount_amount > 0 ? '−'.Listing::format((int) $line->discount_amount, $record->currency) : '—',
                                'total' => Listing::format((int) $line->line_total_amount - (int) $line->discount_amount, $record->currency),
                            ])
                            ->all())
                        ->placeholder('No lines on this order.')
                        ->table([
                            TableColumn::make('Item'),
                            TableColumn::make('Kind'),
                            TableColumn::make('Qty'),
                            TableColumn::make('Each'),
                            TableColumn::make('Discount'),
                            TableColumn::make('Line total'),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('kind')->badge()->color('gray'),
                            TextEntry::make('quantity'),
                            TextEntry::make('unit'),
                            TextEntry::make('discount'),
                            TextEntry::make('total')->weight('medium'),
                        ]),
                ]),

            Section::make('Refunds')
                ->description('Every attempt, including the ones the processor refused.')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('refund_attempts')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => $record->refunds()
                            ->with('issuer:id,name')
                            ->withCount('tickets')
                            ->latest()
                            ->get()
                            ->map(fn (Refund $refund) => [
                                'when' => $refund->created_at?->format('j M Y, H:i'),
                                'amount' => Listing::format((int) $refund->amount, $refund->currency),
                                'tickets' => (string) $refund->tickets_count,
                                'status' => $refund->status,
                                'by' => $refund->issuer?->name ?? 'The platform',
                                'why' => $refund->status === 'failed'
                                    ? 'Failed: '.$refund->failure_reason
                                    : ($refund->reason ?? '—'),
                            ])
                            ->all())
                        ->placeholder('Nothing has been refunded.')
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('Amount'),
                            TableColumn::make('Tickets'),
                            TableColumn::make('Status'),
                            TableColumn::make('By'),
                            TableColumn::make('Reason'),
                        ])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('amount')->weight('medium'),
                            TextEntry::make('tickets'),
                            TextEntry::make('status')->badge()->color(fn (string $state) => match ($state) {
                                'succeeded' => 'success',
                                'pending' => 'warning',
                                default => 'danger',
                            }),
                            TextEntry::make('by'),
                            TextEntry::make('why'),
                        ]),
                ]),

            Section::make('Chargebacks')
                ->collapsible()
                ->collapsed(fn (Order $record) => $record->disputed_at === null)
                ->schema([
                    RepeatableEntry::make('chargebacks')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => Dispute::query()
                            ->where('order_id', $record->id)
                            ->latest('opened_at')
                            ->get()
                            ->map(fn (Dispute $dispute) => [
                                'opened' => $dispute->opened_at?->format('j M Y'),
                                'amount' => Listing::format((int) $dispute->amount, $dispute->currency),
                                'reason' => $dispute->reason ? str_replace('_', ' ', $dispute->reason) : '—',
                                'status' => $dispute->status,
                                'due' => $dispute->evidence_due_at?->format('j M Y') ?? '—',
                            ])
                            ->all())
                        ->placeholder('No chargeback on this order.')
                        ->table([
                            TableColumn::make('Raised'),
                            TableColumn::make('Amount'),
                            TableColumn::make('Their reason'),
                            TableColumn::make('Status'),
                            TableColumn::make('Answer by'),
                        ])
                        ->schema([
                            TextEntry::make('opened'),
                            TextEntry::make('amount'),
                            TextEntry::make('reason'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('due'),
                        ]),
                ]),

            Section::make('What the buyer answered')
                ->collapsible()
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('buyer_answers')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => $record->answers()
                            ->with('question')
                            ->orderBy('attendee_index')
                            ->get()
                            ->map(fn (OrderAnswer $answer) => [
                                'question' => $answer->question?->label ?? 'A removed question',
                                'about' => $answer->attendee_index === null ? 'The order' : 'Guest '.($answer->attendee_index + 1),
                                'answer' => $answer->asText() ?: '—',
                            ])
                            ->all())
                        ->placeholder('This event asked nothing, or nothing was answered.')
                        ->table([
                            TableColumn::make('Question'),
                            TableColumn::make('About'),
                            TableColumn::make('Answer'),
                        ])
                        ->schema([
                            TextEntry::make('question'),
                            TextEntry::make('about')->color('gray'),
                            TextEntry::make('answer'),
                        ]),
                ]),

            Section::make('Staff notes')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('staff_notes')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => AuditTrail::about($record)
                            ->where('action', 'order.staff_note')
                            ->latest('created_at')
                            ->limit(50)
                            ->get()
                            ->map(fn (AuditLog $log) => [
                                'when' => $log->created_at?->format('j M Y, H:i'),
                                'who' => $log->actorName(),
                                'note' => (string) ($log->metadata['note'] ?? ''),
                            ])
                            ->all())
                        ->placeholder('No notes. Add one from the actions above.')
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('Who'),
                            TableColumn::make('Note'),
                        ])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('who'),
                            TextEntry::make('note'),
                        ]),
                ]),

            AuditTrail::section(fn (Order $record) => AuditTrail::about($record)),
        ]);
    }

    /**
     * "GST 5% on the tickets: $10.00", "VAT 7.5% on the service charge, included: ₦11.16".
     *
     * Read the way the receipt reads them, older orders included. Not a tax
     * that came to nothing: an order keeps the rate whatever it was charged
     * on, so free tickets in Toronto carry HST at $0.00, and "No tax on this
     * order" says that better than a line of zeros.
     *
     * @return list<string>
     */
    private static function taxLines(Order $record): array
    {
        return array_values(array_map(
            fn (TaxLine $line) => $line->name.' '.$line->percent().'% on the '
                .($line->on === TaxLine::ON_SERVICE_CHARGE ? 'service charge' : 'tickets')
                .($line->inclusive ? ', included' : '')
                .': '.$line->amount->format(),
            array_filter(Receipt::taxes($record), fn (TaxLine $line) => $line->amount->amount > 0),
        ));
    }

    /**
     * When the organizer paid part of what the processor took: a buyer paid
     * with Klarna or Affirm on a night they opted in (ProcessorEvidence).
     * The order's fee is then the platform's part, and this says the rest.
     */
    private static function feeShared(Order $order): ?string
    {
        $whole = PaymentEvidence::query()->where('order_id', $order->id)->value('fee_amount');

        if ($whole === null || $order->gateway_fee_amount === null || (int) $whole <= (int) $order->gateway_fee_amount) {
            return null;
        }

        $payLater = app(PayLater::class);

        return 'The platform\'s part. '.$payLater->name((string) $payLater->methodOf($order)).' took '
            .Listing::format((int) $whole, $order->currency).' in all; the organizer paid '
            .Listing::format((int) $whole - (int) $order->gateway_fee_amount, $order->currency).' of it for offering paying later.';
    }

    private static function money(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->inlineLabel()
            ->alignEnd()
            ->formatStateUsing(fn ($state, Order $record) => $state === null ? null : Listing::format((int) $state, $record->currency));
    }
}
