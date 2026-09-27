<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\Order;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every order on the platform, for whoever is answering "where are my
 * tickets" or "I was charged twice".
 *
 * Found by what the buyer can tell you: the reference on their email, their
 * address, their name. Money is shown in the currency it was paid in, and the
 * total at the foot is split by currency rather than added across them.
 */
class OrdersTable
{
    public const STATUSES = [
        'pending' => 'Waiting for payment',
        'paid' => 'Paid',
        'partially_refunded' => 'Partly refunded',
        'refunded' => 'Refunded',
        'failed' => 'Payment failed',
        'cancelled' => 'Cancelled',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'orders')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['event:id,title,starts_at,timezone', 'organization:id,name'])
                ->withSum(['refunds as refunded_amount' => fn (Builder $refunds) => $refunds->where('status', 'succeeded')], 'amount'))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Reference, buyer email or name')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(OrderActions::all()),
            ])
            ->toolbarActions([]);
    }

    /** @return list<Column> */
    public static function columns(bool $withEvent = true): array
    {
        return array_values(array_filter([
            TextColumn::make('reference')
                ->label('Order')
                ->searchable()
                ->copyable()
                ->weight('semibold')
                ->fontFamily('mono')
                ->sortable(),

            TextColumn::make('buyer_name')
                ->label('Buyer')
                ->searchable(['buyer_name', 'buyer_email'])
                ->sortable()
                ->description(fn (Order $record) => $record->buyer_email ?? 'No address — sold at the door')
                ->wrap(),

            $withEvent ? TextColumn::make('event.title')
                ->label('Event')
                ->description(fn (Order $record) => $record->organization?->name)
                ->wrap()
                ->toggleable() : null,

            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'paid' => 'success',
                    'pending' => 'warning',
                    'partially_refunded', 'refunded' => 'info',
                    default => 'danger',
                })
                ->description(fn (Order $record) => $record->disputed_at ? 'Chargeback' : null)
                ->sortable(),

            TextColumn::make('channel')
                ->label('Sold')
                ->badge()
                ->color('gray')
                ->formatStateUsing(fn (string $state) => $state === 'door' ? 'At the door' : 'Online')
                ->description(fn (Order $record) => $record->soldAtDoor()
                    ? ucfirst((string) $record->payment_method)
                    : ($record->gateway ? ucfirst($record->gateway) : 'Free'))
                ->toggleable(),

            Listing::money('total_amount', 'Total')
                ->summarize(Listing::totalsPerCurrency('total_amount')),

            Listing::money('refunded_amount', 'Refunded')
                ->placeholder('—')
                ->toggleable(),

            IconColumn::make('disputed_at')
                ->label('Chargeback')
                ->state(fn (Order $record) => $record->disputed_at !== null)
                ->boolean()
                ->trueIcon('heroicon-o-exclamation-triangle')
                ->trueColor('danger')
                ->falseIcon('heroicon-o-minus')
                ->falseColor('gray')
                ->alignCenter()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')
                ->label('Placed')
                ->dateTime('j M Y, H:i')
                ->sortable(),

            TextColumn::make('paid_at')
                ->label('Paid')
                ->dateTime('j M Y, H:i')
                ->placeholder('—')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /** @return list<BaseFilter> */
    public static function filters(bool $withEvent = true): array
    {
        return array_values(array_filter([
            SelectFilter::make('status')
                ->multiple()
                ->options(self::STATUSES),

            $withEvent ? SelectFilter::make('event')
                ->relationship('event', 'title', fn (Builder $query) => $query->withTrashed())
                ->getOptionLabelFromRecordUsing(fn (Event $event) => $event->title.' — '.$event->starts_at?->format('j M Y'))
                ->searchable() : null,

            $withEvent ? SelectFilter::make('organization')
                ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                ->searchable() : null,

            Listing::currency(),

            SelectFilter::make('channel')
                ->label('Sold')
                ->options(['online' => 'Online', 'door' => 'At the door']),

            SelectFilter::make('gateway')
                ->label('Payment processor')
                ->options(['stripe' => 'Stripe', 'paystack' => 'Paystack']),

            Listing::dateRange('placed', 'created_at', 'Placed'),

            TernaryFilter::make('refunded')
                ->label('Refunds')
                ->trueLabel('Has a refund')
                ->falseLabel('No refunds')
                ->queries(
                    true: fn (Builder $query) => $query->whereHas('refunds', fn (Builder $refunds) => $refunds->where('status', 'succeeded')),
                    false: fn (Builder $query) => $query->whereDoesntHave('refunds', fn (Builder $refunds) => $refunds->where('status', 'succeeded')),
                ),

            TernaryFilter::make('disputed')
                ->label('Chargebacks')
                ->trueLabel('Has a chargeback')
                ->falseLabel('No chargeback')
                ->queries(
                    true: fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereNotNull('orders.disputed_at')
                        ->orWhereExists(fn ($disputes) => $disputes->from('disputes')->whereColumn('disputes.order_id', 'orders.id'))),
                    false: fn (Builder $query) => $query
                        ->whereNull('orders.disputed_at')
                        ->whereNotExists(fn ($disputes) => $disputes->from('disputes')->whereColumn('disputes.order_id', 'orders.id')),
                ),
        ]));
    }
}
