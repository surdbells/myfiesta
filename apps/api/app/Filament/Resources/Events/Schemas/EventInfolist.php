<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\AuditTrail;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\TicketType;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One event: how it is selling, what it sells, and what has been done to it.
 */
class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Taken down')
                ->icon('heroicon-o-eye-slash')
                ->iconColor('danger')
                ->visible(fn (Event $record) => $record->taken_down_at !== null)
                ->columns(3)
                ->schema([
                    TextEntry::make('taken_down_reason')->label('Reason sent to the organizer')->columnSpan(2),
                    TextEntry::make('taken_down_by_name')
                        ->label('By')
                        ->state(fn (Event $record) => $record->taken_down_by
                            ? DB::table('users')->where('id', $record->taken_down_by)->value('name')
                            : null)
                        ->placeholder('A former member of staff')
                        ->helperText(fn (Event $record) => $record->taken_down_at
                            ? Carbon::parse($record->taken_down_at)->format('j M Y, H:i').' UTC'
                            : null),
                ]),

            Section::make('Key numbers')
                ->description(fn (Event $record) => 'In '.$record->currency.', the only currency this event sells in.')
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    TextEntry::make('fig_sold')
                        ->label('Tickets sold')
                        ->state(fn (Event $record) => number_format(EventFigures::for($record)['sold']))
                        ->helperText(fn (Event $record) => self::capacityLine(EventFigures::for($record)))
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('fig_arrived')
                        ->label('People in')
                        ->state(fn (Event $record) => number_format(EventFigures::for($record)['arrived']))
                        ->helperText(fn (Event $record) => 'of '.number_format(EventFigures::for($record)['people']).' admitted by valid tickets')
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('fig_gross')
                        ->label('Gross taken')
                        ->state(fn (Event $record) => EventFigures::for($record)['gross'])
                        ->helperText(fn (Event $record) => number_format(EventFigures::for($record)['orders']).' orders, '
                            .number_format(EventFigures::for($record)['door_orders']).' at the door')
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('fig_refunded')
                        ->label('Refunded')
                        ->state(fn (Event $record) => EventFigures::for($record)['refunded'])
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('fig_organizer')
                        ->label('Organizer earned')
                        ->state(fn (Event $record) => EventFigures::for($record)['organizer'])
                        ->helperText('Before refunds, after discounts and tax'),
                    TextEntry::make('fig_service')
                        ->label('Service charges')
                        ->state(fn (Event $record) => EventFigures::for($record)['service'])
                        ->helperText('The platform\'s, paid by buyers, less any tax in them'),
                    TextEntry::make('fig_comps')
                        ->label('Comps and guest list')
                        ->state(fn (Event $record) => number_format(EventFigures::for($record)['comps'])),
                ]),

            Section::make('Event')
                ->columns(3)
                ->collapsible()
                ->schema([
                    TextEntry::make('status_label')
                        ->label('Status')
                        ->badge()
                        ->state(fn (Event $record) => EventsTable::statusOf($record))
                        ->color(fn (string $state) => EventsTable::statusColor($state)),
                    TextEntry::make('organization.name')->label('Organizer'),
                    TextEntry::make('kind')->formatStateUsing(fn (?string $state) => $state === 'invitation' ? 'Invitation (RSVP)' : 'Ticketed'),
                    TextEntry::make('starts_at')
                        ->label('Starts')
                        ->formatStateUsing(fn (Event $record) => $record->starts_at?->timezone($record->timezone)->format('D j M Y, g:ia').' ('.$record->timezone.')'),
                    TextEntry::make('ends_at')
                        ->label('Ends')
                        ->formatStateUsing(fn (Event $record) => $record->ends_at?->timezone($record->timezone)->format('D j M Y, g:ia'))
                        ->placeholder('—'),
                    TextEntry::make('venue.name')
                        ->label('Where')
                        ->state(fn (Event $record) => collect([$record->venue?->name, $record->city, $record->subdivision, $record->country])->filter()->implode(', ')),
                    TextEntry::make('slug')->label('Public link')->prefix('/')->copyable(),
                    TextEntry::make('published_at')->label('First published')->dateTime('j M Y, H:i')->placeholder('Never'),
                    TextEntry::make('approved_at')->label('Last approved')->dateTime('j M Y, H:i')->placeholder('Never'),
                    TextEntry::make('is_featured')->label('Featured')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                    TextEntry::make('cancelled_at')
                        ->label('Cancelled')
                        ->dateTime('j M Y, H:i')
                        ->helperText(fn (Event $record) => $record->cancellation_reason)
                        ->visible(fn (Event $record) => $record->cancelled_at !== null),
                    TextEntry::make('deleted_at')
                        ->label('Deleted by the organizer')
                        ->dateTime('j M Y, H:i')
                        ->visible(fn (Event $record) => $record->deleted_at !== null),
                ]),

            Section::make('Ticket types')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('ticket_type_rows')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => self::ticketTypes($record))
                        ->placeholder('No ticket types.')
                        ->table([
                            TableColumn::make('Type'),
                            TableColumn::make('Price'),
                            TableColumn::make('Status'),
                            TableColumn::make('Sold'),
                            TableColumn::make('Comps'),
                            TableColumn::make('Capacity'),
                        ])
                        ->schema([
                            TextEntry::make('name')->weight('medium'),
                            TextEntry::make('price'),
                            TextEntry::make('status')->badge()->color('gray'),
                            TextEntry::make('sold'),
                            TextEntry::make('comps'),
                            TextEntry::make('capacity'),
                        ]),
                ]),

            AuditTrail::section(fn (Event $record) => AuditTrail::about($record)),
        ]);
    }

    /** @return list<array<string, string>> */
    private static function ticketTypes(Event $event): array
    {
        $counts = DB::table('tickets')
            ->where('event_id', $event->id)
            ->whereIn('status', EventsTable::PLACES)
            ->groupBy('ticket_type_id')
            ->selectRaw('ticket_type_id')
            ->selectRaw('count(*) filter (where order_id is not null) as sold')
            ->selectRaw('count(*) filter (where order_id is null) as comps')
            ->get()
            ->keyBy('ticket_type_id');

        return TicketType::withTrashed()
            ->where('event_id', $event->id)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TicketType $type) => [
                'name' => $type->name.($type->trashed() ? ' (removed)' : ''),
                'price' => Listing::format((int) $type->price_amount, $event->currency),
                'status' => str_replace('_', ' ', (string) $type->status),
                'sold' => number_format((int) ($counts[$type->id]->sold ?? 0)),
                'comps' => number_format((int) ($counts[$type->id]->comps ?? 0)),
                'capacity' => $type->quantity_available === null ? 'Unlimited' : number_format((int) $type->quantity_available),
            ])
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $figures */
    private static function capacityLine(array $figures): string
    {
        return match (true) {
            $figures['capacity'] === null => 'No ticket types set up',
            $figures['capacity'] === 'unlimited' => 'No cap on at least one type',
            default => 'of '.number_format((int) $figures['capacity']).' places',
        };
    }
}
