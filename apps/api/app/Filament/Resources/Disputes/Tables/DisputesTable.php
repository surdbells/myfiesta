<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Listing;
use App\Models\Dispute;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The chargeback list: who, how much, why, and how long is left.
 *
 * Amounts in the currency the buyer disputed in; the total at the foot is one
 * figure per currency, never a sum across them.
 */
class DisputesTable
{
    public const STATUSES = [
        'open' => 'Open',
        'won' => 'Kept the money',
        'lost' => 'Money taken back',
        'withdrawn' => 'Withdrawn',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'chargebacks')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'organization:id,name',
                'order:id,reference,buyer_name,buyer_email',
                'event:id,title',
            ]))
            ->defaultSort('opened_at', 'desc')
            ->searchPlaceholder('Order, buyer, organizer or event')
            ->columns([
                TextColumn::make('opened_at')
                    ->label('Raised')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('organization.name')
                    ->label('Organizer')
                    ->searchable()
                    ->description(fn (Dispute $record) => $record->event?->title)
                    ->wrap(),

                TextColumn::make('event.title')
                    ->label('Event')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('order.reference')
                    ->label('Order')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable()
                    ->description(fn (Dispute $record) => $record->order?->buyer_email)
                    ->url(fn (Dispute $record) => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null),

                // Searchable but hidden: the address a caller gives is often
                // the only thing they know about the order.
                TextColumn::make('order.buyer_email')
                    ->label('Buyer')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Listing::money('amount', 'Amount')
                    ->summarize(Listing::totalsPerCurrency('amount')),

                TextColumn::make('reason')
                    ->label('Their reason')
                    ->formatStateUsing(fn (?string $state) => $state ? str_replace('_', ' ', $state) : '—')
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'won' => 'success',
                        'lost' => 'danger',
                        'open' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('gateway')
                    ->label('Processor')
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('gateway_reference')
                    ->label('Processor reference')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                // The only date that can still be acted on. After it, nobody
                // can do anything about this one.
                TextColumn::make('evidence_due_at')
                    ->label('Answer by')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->color(fn (Dispute $record) => $record->isOpen() && $record->evidence_due_at?->isPast() ? 'danger' : 'gray')
                    ->description(fn (Dispute $record) => $record->isOpen() && $record->evidence_due_at?->isFuture()
                        ? $record->evidence_due_at->diffForHumans()
                        : null)
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label('Closed')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(self::STATUSES),

                SelectFilter::make('gateway')
                    ->label('Processor')
                    ->options(['stripe' => 'Stripe', 'paystack' => 'Paystack']),

                Listing::currency(),

                SelectFilter::make('organization')
                    ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                    ->searchable(),

                // Open, with the processor's deadline inside a week or already
                // gone: the ones somebody should be chasing today.
                TernaryFilter::make('due_soon')
                    ->label('Deadline')
                    ->trueLabel('Open, answer due within 7 days or overdue')
                    ->falseLabel('Open, more than 7 days left')
                    ->queries(
                        true: fn (Builder $query) => $query
                            ->where('status', 'open')
                            ->whereNotNull('evidence_due_at')
                            ->where('evidence_due_at', '<=', now()->addDays(7)),
                        false: fn (Builder $query) => $query
                            ->where('status', 'open')
                            ->where(fn (Builder $q) => $q
                                ->whereNull('evidence_due_at')
                                ->orWhere('evidence_due_at', '>', now()->addDays(7))),
                    ),

                Listing::dateRange('raised', 'opened_at', 'Raised'),
            ])
            ->recordActions([
                Action::make('openOrder')
                    ->label('Open order')
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->color('gray')
                    ->visible(fn (Dispute $record) => $record->order_id !== null && OrderResource::canViewAny())
                    ->url(fn (Dispute $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
            ])
            ->toolbarActions([]);
    }
}
