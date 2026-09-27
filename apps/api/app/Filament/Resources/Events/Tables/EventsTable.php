<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\Actions\EventActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\TicketType;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every event on the platform, with how it is selling.
 *
 * Sold is tickets that still admit somebody (comps included — they take a
 * place); capacity is the sum of what each ticket type allows, or unlimited
 * when any of them is. Gross is what buyers paid, in the event's own currency.
 */
class EventsTable
{
    /** Tickets that take a place in the room. */
    public const PLACES = ['valid', 'checked_in', 'listed'];

    /** Orders whose money was taken, whether or not some went back. */
    public const TAKEN = ['paid', 'partially_refunded', 'refunded'];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'events')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['organization:id,name'])
                ->withCount(['tickets as sold_count' => fn (Builder $tickets) => $tickets->whereIn('status', self::PLACES)])
                ->withSum(['orders as gross_amount' => fn (Builder $orders) => $orders->whereIn('status', self::TAKEN)], 'total_amount')
                ->addSelect([
                    'capacity_total' => TicketType::query()
                        ->selectRaw('sum(quantity_available)')
                        ->whereColumn('ticket_types.event_id', 'events.id')
                        ->whereNull('ticket_types.deleted_at'),
                    'capacity_unlimited' => TicketType::query()
                        ->selectRaw('coalesce(bool_or(quantity_available is null), false)')
                        ->whereColumn('ticket_types.event_id', 'events.id')
                        ->whereNull('ticket_types.deleted_at'),
                ]))
            ->defaultSort('starts_at', 'desc')
            ->searchPlaceholder('Title, organizer or city')
            ->columns([
                TextColumn::make('title')
                    ->label('Event')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Event $record) => $record->organization?->name)
                    ->wrap(),

                TextColumn::make('organization.name')
                    ->label('Organizer')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('city')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Event $record) => $record->country),

                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->sortable()
                    ->state(fn (Event $record) => $record->starts_at)
                    ->formatStateUsing(fn (Event $record) => $record->starts_at?->timezone($record->timezone)->format('D j M Y, g:ia'))
                    ->description(fn (Event $record) => $record->starts_at?->diffForHumans()),

                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Event $record) => self::statusOf($record))
                    ->color(fn (string $state) => match ($state) {
                        'On sale' => 'success',
                        'Draft' => 'gray',
                        'Cancelled' => 'warning',
                        default => 'danger',
                    })
                    ->sortable(['status']),

                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('sold_count')
                    ->label('Sold')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->description(fn (Event $record) => 'of '.self::capacityOf($record)),

                Listing::money('gross_amount', 'Gross')
                    ->placeholder('—')
                    ->summarize(Listing::totalsPerCurrency('gross_amount')),

                TextColumn::make('currency')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'On sale',
                        'cancelled' => 'Cancelled',
                    ]),

                TernaryFilter::make('taken_down')
                    ->label('Taken down')
                    ->trueLabel('Taken down by us')
                    ->falseLabel('Not taken down')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('events.taken_down_at'),
                        false: fn (Builder $query) => $query->whereNull('events.taken_down_at'),
                    ),

                SelectFilter::make('organization')
                    ->relationship('organization', 'name', fn (Builder $query) => $query->withTrashed())
                    ->searchable(),

                SelectFilter::make('country')
                    ->options(fn () => Event::query()
                        ->withTrashed()
                        ->distinct()
                        ->orderBy('country')
                        ->pluck('country', 'country')
                        ->all()),

                Listing::currency(),

                TernaryFilter::make('upcoming')
                    ->label('When')
                    ->trueLabel('Upcoming')
                    ->falseLabel('Past')
                    ->queries(
                        true: fn (Builder $query) => $query->where('events.starts_at', '>=', now()),
                        false: fn (Builder $query) => $query->where('events.starts_at', '<', now()),
                    ),

                Listing::dateRange('starts', 'starts_at', 'Starts'),

                TernaryFilter::make('has_sales')
                    ->label('Sales')
                    ->trueLabel('Has sold something')
                    ->falseLabel('No sales yet')
                    ->queries(
                        true: fn (Builder $query) => $query->whereExists(self::paidOrders()),
                        false: fn (Builder $query) => $query->whereNotExists(self::paidOrders()),
                    ),

                TernaryFilter::make('is_featured')->label('Featured'),

                TrashedFilter::make()
                    ->label('Deleted by the organizer')
                    ->placeholder('Not deleted')
                    ->trueLabel('Include deleted')
                    ->falseLabel('Deleted only'),
            ])
            ->recordUrl(fn (Event $record) => EventResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([EventActions::publicPage(), ...EventActions::all()]),
            ])
            ->toolbarActions([]);
    }

    public static function statusOf(Event $event): string
    {
        return match (true) {
            $event->deleted_at !== null => 'Deleted',
            $event->taken_down_at !== null => 'Taken down',
            $event->status === 'published' => 'On sale',
            $event->status === 'cancelled' => 'Cancelled',
            default => 'Draft',
        };
    }

    public static function capacityOf(Event $event): string
    {
        if ($event->capacity_unlimited ?? false) {
            return 'unlimited';
        }

        return $event->capacity_total === null ? 'no tickets set up' : number_format((int) $event->capacity_total);
    }

    private static function paidOrders(): \Closure
    {
        return fn ($orders) => $orders
            ->select(DB::raw(1))
            ->from('orders')
            ->whereColumn('orders.event_id', 'events.id')
            ->whereIn('orders.status', self::TAKEN);
    }
}
