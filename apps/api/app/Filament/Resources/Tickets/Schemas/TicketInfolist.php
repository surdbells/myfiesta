<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AuditTrail;
use App\Models\Ticket;
use App\Models\TicketScan;
use App\Models\TicketTransfer;
use App\Services\StaffSupport\MaskedCode;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One ticket's story: who holds it, where it came from, every time it changed
 * hands, and every time a door looked at it.
 *
 * The scans are shown by their result, never by the code that was scanned.
 */
class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ticket')
                ->columns(3)
                ->schema([
                    TextEntry::make('ticketType.name')->label('Type')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => TicketsTable::STATUSES[$state] ?? $state)
                        ->color(fn (string $state) => match ($state) {
                            'valid' => 'success',
                            'checked_in' => 'info',
                            'listed', 'transferred' => 'warning',
                            default => 'danger',
                        }),
                    TextEntry::make('code_tail')
                        ->label('Code')
                        ->state(fn (Ticket $record) => MaskedCode::of($record->code))
                        ->fontFamily('mono')
                        ->helperText('Masked. Resend the ticket to put it in the holder\'s inbox.'),

                    TextEntry::make('holder_name')->label('Holder')->placeholder('No name given'),
                    TextEntry::make('owner_email')->label('Holder email')->copyable()->placeholder('—'),
                    TextEntry::make('account')
                        ->label('Account')
                        ->state(fn (Ticket $record) => UserResource::accountLabel($record->owner_user_id))
                        ->url(fn (Ticket $record) => $record->owner_user_id ? UserResource::getUrl('view', ['record' => $record->owner_user_id]) : null)
                        ->placeholder('No account'),

                    TextEntry::make('event.title')
                        ->label('Event')
                        ->url(fn (Ticket $record) => $record->event ? EventResource::getUrl('view', ['record' => $record->event]) : null)
                        ->helperText(fn (Ticket $record) => $record->event?->starts_at?->timezone($record->event->timezone)->format('D j M Y, g:ia')),
                    TextEntry::make('order.reference')
                        ->label('Order')
                        ->fontFamily('mono')
                        ->url(fn (Ticket $record) => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
                        ->placeholder('Guest list or comp — no order'),
                    TextEntry::make('created_at')->label('Issued')->dateTime('j M Y, H:i'),

                    TextEntry::make('admitted')
                        ->label('Admitted')
                        ->state(fn (Ticket $record) => $record->admitted_count.' of '.$record->admits),
                    TextEntry::make('checked_in_at')->label('First in')->dateTime('j M Y, H:i')->placeholder('Not yet'),
                    TextEntry::make('checked_in_by_name')
                        ->label('Let in by')
                        ->state(fn (Ticket $record) => $record->checked_in_by
                            ? DB::table('users')->where('id', $record->checked_in_by)->value('name')
                            : null)
                        ->placeholder('—'),
                ]),

            Section::make('Changed hands')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('ticket_transfers')
                        ->hiddenLabel()
                        ->state(fn (Ticket $record) => $record->transfers()
                            ->with('initiator:id,name,platform_role')
                            ->latest('transferred_at')
                            ->get()
                            ->map(fn (TicketTransfer $transfer) => [
                                'when' => $transfer->transferred_at?->format('j M Y, H:i'),
                                'from' => $transfer->from_email ?: '—',
                                'to' => $transfer->to_email,
                                // A send from a ticket link has no account
                                // behind it, only whoever held the link.
                                'by' => $transfer->initiator
                                    ? $transfer->initiator->name.($transfer->initiator->platform_role ? ' (myFiesta staff)' : '')
                                    : ($transfer->via === 'link' ? 'The holder, from their ticket link' : '—'),
                            ])
                            ->all())
                        ->placeholder('Never transferred.')
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('From'),
                            TableColumn::make('To'),
                            TableColumn::make('By'),
                        ])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('from'),
                            TextEntry::make('to'),
                            TextEntry::make('by'),
                        ]),
                ]),

            Section::make('At the door')
                ->description('Every scan of this ticket, including refusals. The last 25.')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('door_scans')
                        ->hiddenLabel()
                        ->state(fn (Ticket $record) => TicketScan::query()
                            ->where('ticket_id', $record->id)
                            ->with('scanner:id,name')
                            ->latest('scanned_at')
                            ->limit(25)
                            ->get()
                            ->map(fn (TicketScan $scan) => [
                                'when' => $scan->scanned_at ? Carbon::parse($scan->scanned_at)->format('j M Y, H:i:s') : '—',
                                'result' => str_replace('_', ' ', (string) $scan->result),
                                'admitted' => $scan->admitted !== null ? (string) $scan->admitted : '—',
                                'by' => $scan->scanner?->name ?? '—',
                            ])
                            ->all())
                        ->placeholder('Never scanned.')
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('Result'),
                            TableColumn::make('Let in'),
                            TableColumn::make('Scanned by'),
                        ])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('result')->badge()->color(fn (string $state) => $state === 'accepted' ? 'success' : 'warning'),
                            TextEntry::make('admitted'),
                            TextEntry::make('by'),
                        ]),
                ]),

            AuditTrail::section(fn (Ticket $record) => AuditTrail::about($record)),
        ]);
    }
}
