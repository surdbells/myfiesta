<?php

namespace App\Filament\Resources\Tickets\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\Actions\TicketRowActions;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\StaffSupport\MaskedCode;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every ticket, found by who holds it or the order it came from.
 *
 * Never by its code, and never showing it. A code opens a door; a support
 * screen that could look one up would let anybody with a login turn a name
 * into a way in, and one that displayed them would put a door's worth of
 * entries on a laptop screen. The masked tail is enough to match what a
 * caller reads from their own email.
 */
class TicketsTable
{
    public const STATUSES = [
        'valid' => 'Valid',
        'checked_in' => 'Checked in',
        'listed' => 'Listed for resale',
        'transferred' => 'Transferred',
        'refunded' => 'Refunded',
        'void' => 'Void',
    ];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'tickets')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'event:id,title,starts_at,timezone,organization_id',
                'ticketType:id,name',
                'order:id,reference,buyer_email',
            ]))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Holder name, email or order reference')
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordUrl(fn (Ticket $record) => TicketResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(TicketRowActions::all()),
            ])
            ->toolbarActions([]);
    }

    /** @return list<Column> */
    public static function columns(bool $withEvent = true, bool $withOrder = true): array
    {
        return array_values(array_filter([
            TextColumn::make('holder_name')
                ->label('Holder')
                ->searchable(['holder_name', 'owner_email'])
                ->sortable()
                ->placeholder('No name given')
                ->description(fn (Ticket $record) => $record->owner_email ?: $record->order?->buyer_email)
                ->wrap(),

            $withEvent ? TextColumn::make('event.title')
                ->label('Event')
                ->description(fn (Ticket $record) => $record->event?->starts_at?->timezone($record->event->timezone)->format('j M Y'))
                ->wrap()
                ->toggleable() : null,

            TextColumn::make('ticketType.name')
                ->label('Type')
                ->placeholder('—'),

            $withOrder ? TextColumn::make('order.reference')
                ->label('Order')
                ->searchable()
                ->fontFamily('mono')
                ->placeholder('Guest list')
                ->url(fn (Ticket $record) => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null) : null,

            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    'valid' => 'success',
                    'checked_in' => 'info',
                    'listed', 'transferred' => 'warning',
                    default => 'danger',
                })
                ->sortable(),

            TextColumn::make('admitted_count')
                ->label('In')
                ->state(fn (Ticket $record) => $record->admitted_count.' of '.$record->admits)
                ->description(fn (Ticket $record) => $record->checked_in_at?->format('j M, H:i'))
                ->sortable(['admitted_count'])
                ->alignEnd(),

            // Enough to match what somebody reads out from their email.
            TextColumn::make('code_tail')
                ->label('Code')
                ->state(fn (Ticket $record) => MaskedCode::of($record->code))
                ->fontFamily('mono')
                ->color('gray')
                ->toggleable(),

            TextColumn::make('created_at')
                ->label('Issued')
                ->dateTime('j M Y, H:i')
                ->sortable(),
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

            SelectFilter::make('ticketType')
                ->label('Ticket type')
                ->relationship('ticketType', 'name', fn (Builder $query) => $query->withTrashed()->with('event:id,title'))
                ->getOptionLabelFromRecordUsing(fn (TicketType $type) => $type->name.' — '.($type->event?->title ?? 'unknown event'))
                ->searchable(),

            TernaryFilter::make('checked_in')
                ->label('Checked in')
                ->trueLabel('Somebody has come in on it')
                ->falseLabel('Not used yet')
                ->queries(
                    true: fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->where('tickets.admitted_count', '>', 0)
                        ->orWhereNotNull('tickets.checked_in_at')),
                    false: fn (Builder $query) => $query
                        ->where('tickets.admitted_count', 0)
                        ->whereNull('tickets.checked_in_at'),
                ),

            TernaryFilter::make('from_order')
                ->label('Source')
                ->trueLabel('Bought on an order')
                ->falseLabel('Guest list or comp')
                ->queries(
                    true: fn (Builder $query) => $query->whereNotNull('tickets.order_id'),
                    false: fn (Builder $query) => $query->whereNull('tickets.order_id'),
                ),

            Listing::dateRange('issued', 'created_at', 'Issued'),
        ]));
    }
}
