<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Disputes\Schemas\DisputeInfolist;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Listing;
use App\Models\Dispute;
use App\Services\Disputes\Reasons;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The chargeback list: what needs answering first, and how long is left.
 *
 * Open, unanswered disputes come first, soonest deadline first, and any due
 * within three days has its deadline in red — after the deadline the
 * processor takes nothing. Amounts in the currency the buyer disputed in; the
 * total at the foot is one figure per currency, never a sum across them.
 */
class DisputesTable
{
    public const STATUSES = [
        'open' => 'Open',
        'won' => 'Kept the money',
        'lost' => 'Money taken back',
        'withdrawn' => 'Withdrawn',
    ];

    /** Where the answer to each stands. */
    public const ANSWERS = [
        'none' => 'Not put together',
        'draft' => 'Put together, not sent',
        'edited' => 'Edited, not sent',
        'submitted' => 'Evidence sent',
        'accepted' => 'Accepted',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'chargebacks')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'organization:id,name',
                'order:id,reference,buyer_name,buyer_email',
                'event:id,title',
                'evidence:id,dispute_id,edited_at',
            ]))
            // What needs answering first: open and unanswered, by deadline.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw("case when status = 'open' and response is null then 0 else 1 end")
                ->orderByRaw('evidence_due_at asc nulls last')
                ->orderByDesc('opened_at'))
            ->searchPlaceholder('Order, buyer, organizer or event')
            ->recordUrl(fn (Dispute $record) => DisputeResource::getUrl('view', ['record' => $record]))
            ->columns([
                // The only date that can still be acted on. Red inside three
                // days, and once it has gone.
                TextColumn::make('evidence_due_at')
                    ->label('Answer by')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->color(fn (Dispute $record) => DisputeInfolist::urgency($record))
                    ->weight(fn (Dispute $record) => DisputeInfolist::urgency($record) === 'danger' ? FontWeight::Bold : null)
                    ->icon(fn (Dispute $record) => DisputeInfolist::urgency($record) === 'danger' ? Heroicon::OutlinedExclamationCircle : null)
                    ->description(fn (Dispute $record) => DisputeInfolist::timeLeft($record))
                    ->sortable(),

                TextColumn::make('answer')
                    ->label('Our answer')
                    ->badge()
                    ->state(fn (Dispute $record) => self::ANSWERS[self::answer($record)])
                    ->color(fn (Dispute $record) => match (self::answer($record)) {
                        'none' => 'danger',
                        'draft', 'edited' => 'warning',
                        'submitted' => 'info',
                        default => 'gray',
                    }),

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
                    ->description(fn (Dispute $record) => $record->order?->buyer_email),

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
                    ->formatStateUsing(fn (?string $state) => Reasons::label($state))
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

                TextColumn::make('opened_at')
                    ->label('Raised')
                    ->dateTime('j M Y, H:i')
                    ->sortable()
                    ->toggleable(),

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

                SelectFilter::make('reason')
                    ->label('Their reason')
                    ->multiple()
                    ->options(fn () => Dispute::query()
                        ->whereNotNull('reason')
                        ->distinct()
                        ->orderBy('reason')
                        ->pluck('reason')
                        ->mapWithKeys(fn (string $reason) => [$reason => Reasons::label($reason)])
                        ->all()),

                SelectFilter::make('gateway')
                    ->label('Processor')
                    ->options(['stripe' => 'Stripe', 'paystack' => 'Paystack']),

                // Open and unanswered, by how long is left: the ones somebody
                // should be chasing today are the first two.
                SelectFilter::make('deadline')
                    ->label('Deadline')
                    ->options([
                        'overdue' => 'Past its deadline, unanswered',
                        'three_days' => 'Due within 3 days',
                        'week' => 'Due within 7 days',
                        'later' => 'More than 7 days left',
                        'none' => 'No deadline given',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        $query->where('status', 'open')->whereNull('response');

                        return match ($value) {
                            'overdue' => $query->where('evidence_due_at', '<', now()),
                            'three_days' => $query->whereBetween('evidence_due_at', [now(), now()->addDays(DisputeInfolist::URGENT_DAYS)]),
                            'week' => $query->whereBetween('evidence_due_at', [now(), now()->addDays(7)]),
                            'later' => $query->where('evidence_due_at', '>', now()->addDays(7)),
                            'none' => $query->whereNull('evidence_due_at'),
                            default => $query,
                        };
                    }),

                SelectFilter::make('answer')
                    ->label('Our answer')
                    ->options(self::ANSWERS)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'none' => $query->whereNull('response')->whereDoesntHave('evidence'),
                        'draft' => $query->whereNull('response')->whereHas('evidence'),
                        'edited' => $query->whereNull('response')->whereHas('evidence', fn (Builder $evidence) => $evidence->whereNotNull('edited_at')),
                        'submitted' => $query->where('response', Dispute::SUBMITTED),
                        'accepted' => $query->where('response', Dispute::ACCEPTED),
                        default => $query,
                    }),

                Listing::currency(),

                SelectFilter::make('organization')
                    ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                    ->searchable(),

                Listing::dateRange('raised', 'opened_at', 'Raised'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Answer')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (Dispute $record) => DisputeResource::getUrl('view', ['record' => $record])),

                Action::make('openOrder')
                    ->label('Open order')
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->color('gray')
                    ->visible(fn (Dispute $record) => $record->order_id !== null && OrderResource::canViewAny())
                    ->url(fn (Dispute $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
            ])
            ->toolbarActions([]);
    }

    /** One of ANSWERS' keys. */
    public static function answer(Dispute $record): string
    {
        return match (true) {
            $record->response === Dispute::SUBMITTED => 'submitted',
            $record->response === Dispute::ACCEPTED => 'accepted',
            $record->evidence === null => 'none',
            $record->evidence->edited_at !== null => 'edited',
            default => 'draft',
        };
    }
}
