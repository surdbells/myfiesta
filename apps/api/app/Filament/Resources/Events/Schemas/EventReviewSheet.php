<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Resources\Organizations\Schemas\OrganizationInfolist;
use App\Models\AddOn;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\EventQuestion;
use App\Models\EventReview;
use App\Models\TicketType;
use App\Services\Events\EventReviews;
use App\Support\Money;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * An event as its buyers will see it, and what a reviewer needs to decide.
 *
 * Everything on the public page and in the checkout — the description as it
 * renders, the poster and gallery, every ticket and add-on with its price, the
 * questions, the dates in the event's own zone, where it is and who it admits
 * — read from the event as it stands, which is what approving puts on sale.
 * Above it, who is asking: their verification, whether they are suspended,
 * the disputes against them, and, for an event approved before, what has
 * changed since.
 */
final class EventReviewSheet
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where it stands')
                ->columns(['default' => 2, 'md' => 3])
                ->schema([
                    TextEntry::make('review_status')
                        ->label('Status')
                        ->badge()
                        ->state(fn (Event $record) => EventsTable::statusOf($record))
                        ->color(fn (string $state) => EventsTable::statusColor($state)),
                    TextEntry::make('review_waiting')
                        ->label('Waiting')
                        ->state(fn (Event $record) => $record->submitted_at === null
                            ? 'Not waiting for review'
                            : 'Sent '.$record->submitted_at->diffForHumans())
                        ->helperText(fn (Event $record) => $record->submitted_at?->format('j M Y, H:i').($record->submitted_at ? ' UTC' : '')),
                    TextEntry::make('review_history_summary')
                        ->label('Reviewed before')
                        ->state(fn (Event $record) => self::before($record)),
                    TextEntry::make('organization.name')
                        ->label('Organizer')
                        ->helperText(fn (Event $record) => $record->organization
                            ? OrganizationInfolist::verification($record->organization)
                            : null),
                    TextEntry::make('review_standing')
                        ->label('Standing')
                        ->state(fn (Event $record) => $record->organization?->suspended_at !== null
                            ? 'Suspended since '.$record->organization->suspended_at->format('j M Y')
                            : 'Selling normally')
                        ->color(fn (Event $record) => $record->organization?->suspended_at !== null ? 'danger' : null),
                    TextEntry::make('review_disputes')
                        ->label('Disputes against them')
                        ->state(fn (Event $record) => self::disputes($record)),
                ]),

            Section::make('Sent back last time')
                ->icon('heroicon-o-arrow-uturn-left')
                ->iconColor('warning')
                ->visible(fn (Event $record) => self::lastRejection($record) !== null)
                ->schema([
                    TextEntry::make('review_last_rejection')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => self::lastRejection($record)?->reason)
                        ->helperText(fn (Event $record) => ($at = self::lastRejection($record)?->created_at)
                            ? 'Sent back '.$at->format('j M Y').' by '.(self::lastRejection($record)->actor_label ?? 'a former member of staff')
                            : null),
                ]),

            Section::make('Changed since it was last approved')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (Event $record) => app(EventReviews::class)->changesSinceApproval($record) !== null)
                ->description(fn (Event $record) => $record->approved_at ? 'Approved '.$record->approved_at->format('j M Y').'. What a buyer sees now, against then.' : null)
                ->schema([
                    TextEntry::make('review_changes')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => app(EventReviews::class)->changesSinceApproval($record) ?: ['Nothing a buyer sees has changed.'])
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),

            Section::make('The listing')
                ->description('As the public page shows it.')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    ImageEntry::make('review_poster')
                        ->label('Poster')
                        ->disk('public')
                        ->checkFileExistence(false)
                        ->state(fn (Event $record) => self::rendition($record->banner()->first()))
                        ->imageHeight(220)
                        ->placeholder('No poster'),
                    TextEntry::make('title')
                        ->weight('semibold')
                        ->size('lg')
                        ->helperText(fn (Event $record) => $record->category ?: 'No category'),
                    TextEntry::make('review_when')
                        ->label('When')
                        ->state(fn (Event $record) => self::when($record))
                        ->helperText(fn (Event $record) => 'In the event’s own time zone, '.$record->timezone),
                    TextEntry::make('review_where')
                        ->label('Where')
                        ->state(fn (Event $record) => collect([
                            $record->venue?->name,
                            $record->venue?->address_line,
                            $record->city,
                            $record->subdivision,
                            $record->country,
                        ])->filter()->implode(', ')),
                    TextEntry::make('review_admits')
                        ->label('Who it admits')
                        ->state(fn (Event $record) => collect([
                            $record->min_age ? $record->min_age.'+ only' : 'All ages',
                            $record->id_required ? 'ID checked at the door' : 'No ID check',
                            $record->dress_code ? 'Dress code: '.$record->dress_code : null,
                        ])->filter()->implode(' · ')),
                    TextEntry::make('kind')
                        ->label('Kind')
                        ->formatStateUsing(fn (?string $state) => $state === 'invitation' ? 'Invitation (RSVP)' : 'Ticketed'),
                    TextEntry::make('description')
                        ->html()
                        ->placeholder('No description')
                        ->columnSpanFull(),
                    ImageEntry::make('review_gallery')
                        ->label('Gallery')
                        ->disk('public')
                        ->checkFileExistence(false)
                        ->state(fn (Event $record) => $record->gallery()->get()->map(fn (EventImage $image) => self::rendition($image))->filter()->values()->all())
                        ->imageHeight(120)
                        ->placeholder('No gallery pictures')
                        ->columnSpanFull(),
                ]),

            Section::make('Tickets')
                ->description(fn (Event $record) => 'Prices in '.$record->currency.'. Sale times in '.$record->timezone.'.')
                ->schema([
                    RepeatableEntry::make('review_ticket_types')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => self::ticketTypes($record))
                        ->placeholder('No ticket types.')
                        ->table([
                            TableColumn::make('Ticket'),
                            TableColumn::make('About'),
                            TableColumn::make('Price'),
                            TableColumn::make('How many'),
                            TableColumn::make('On sale'),
                            TableColumn::make('Status'),
                        ])
                        ->schema([
                            TextEntry::make('name')->weight('medium'),
                            TextEntry::make('description')->placeholder('—'),
                            TextEntry::make('price'),
                            TextEntry::make('quantity'),
                            TextEntry::make('window'),
                            TextEntry::make('status')->badge()->color('gray'),
                        ]),
                ]),

            Section::make('Add-ons')
                ->visible(fn (Event $record) => $record->addOns()->exists())
                ->schema([
                    RepeatableEntry::make('review_add_ons')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => $record->addOns()->get()->map(fn (AddOn $addOn) => [
                            'name' => $addOn->name,
                            'description' => $addOn->description,
                            'price' => Money::of((int) $addOn->price_amount, $record->currency)->format(),
                            'quantity' => $addOn->quantity_available === null ? 'Unlimited' : number_format((int) $addOn->quantity_available),
                            'status' => str_replace('_', ' ', (string) $addOn->status),
                        ])->values()->all())
                        ->table([
                            TableColumn::make('Add-on'),
                            TableColumn::make('Price'),
                            TableColumn::make('How many'),
                            TableColumn::make('Status'),
                        ])
                        ->schema([
                            TextEntry::make('name')->weight('medium'),
                            TextEntry::make('price'),
                            TextEntry::make('quantity'),
                            TextEntry::make('status')->badge()->color('gray'),
                        ]),
                ]),

            Section::make('What the checkout asks')
                ->visible(fn (Event $record) => $record->questions()->exists())
                ->schema([
                    RepeatableEntry::make('review_questions')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => $record->questions()->get()->map(fn (EventQuestion $question) => [
                            'label' => $question->label,
                            'answer' => match ($question->type) {
                                'choice' => 'One of: '.implode(', ', (array) $question->options),
                                'multi_choice' => 'Any of: '.implode(', ', (array) $question->options),
                                'boolean' => 'Yes or no',
                                default => 'Written answer',
                            },
                            'asked' => ($question->required ? 'Required' : 'Optional').', '.($question->per_attendee ? 'of each guest' : 'once per order'),
                        ])->values()->all())
                        ->table([
                            TableColumn::make('Question'),
                            TableColumn::make('Answer'),
                            TableColumn::make('Asked'),
                        ])
                        ->schema([
                            TextEntry::make('label')->weight('medium'),
                            TextEntry::make('answer'),
                            TextEntry::make('asked'),
                        ]),
                ]),

            Section::make('Review history')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('review_steps')
                        ->hiddenLabel()
                        ->state(fn (Event $record) => EventReview::query()
                            ->where('event_id', $record->id)
                            ->orderByDesc('seq')
                            ->limit(50)
                            ->get()
                            ->map(fn (EventReview $step) => [
                                'when' => $step->created_at?->format('j M Y, H:i').' UTC',
                                'what' => self::stepLabel($step),
                                'by' => $step->actor_label ?? ($step->actor_id === null ? 'myFiesta' : 'A former member'),
                                'reason' => $step->reason,
                            ])->values()->all())
                        ->placeholder('Never sent for review.')
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('What'),
                            TableColumn::make('By'),
                            TableColumn::make('Reason'),
                        ])
                        ->schema([
                            TextEntry::make('when'),
                            TextEntry::make('what')->weight('medium'),
                            TextEntry::make('by'),
                            TextEntry::make('reason')->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    public static function stepLabel(EventReview $step): string
    {
        return match ($step->action) {
            EventReview::SUBMITTED => 'Sent for review',
            EventReview::WITHDRAWN => 'Taken back by the organizer',
            EventReview::REJECTED => 'Sent back',
            EventReview::APPROVED => match ($step->via) {
                EventReviews::VIA_TAKEDOWN_LIFTED => 'Approved: takedown lifted',
                EventReviews::VIA_SUSPENSION_LIFTED => 'Approved: suspension lifted',
                EventReviews::VIA_SERIES => 'Approved: next date of an approved series',
                EventReviews::VIA_EXISTING => 'Approved: on sale before reviews began',
                EventReviews::VIA_IMPORTED => 'Approved: imported on sale',
                default => 'Approved',
            },
            default => ucfirst($step->action),
        };
    }

    /** How this event has fared in review before now. */
    private static function before(Event $event): string
    {
        $rejections = EventReview::query()->where('event_id', $event->id)->where('action', EventReview::REJECTED)->count();

        return match (true) {
            $event->approved_at !== null && $rejections > 0 => 'Approved before, and sent back '.self::times($rejections),
            $event->approved_at !== null => 'Approved before — see what changed below',
            $rejections > 0 => 'Sent back '.self::times($rejections),
            default => 'First review',
        };
    }

    private static function times(int $n): string
    {
        return $n === 1 ? 'once' : ($n === 2 ? 'twice' : $n.' times');
    }

    /** The last time it was sent back, when it has been sent again since. */
    private static function lastRejection(Event $event): ?EventReview
    {
        return EventReview::query()
            ->where('event_id', $event->id)
            ->where('action', EventReview::REJECTED)
            ->orderByDesc('seq')
            ->first();
    }

    private static function disputes(Event $event): string
    {
        $all = Dispute::query()->where('organization_id', $event->organization_id)->count();

        if ($all === 0) {
            return 'None';
        }

        $open = Dispute::query()->where('organization_id', $event->organization_id)->where('status', 'open')->count();

        return number_format($all).' in all'.($open > 0 ? ', '.number_format($open).' open' : '');
    }

    private static function when(Event $event): string
    {
        $zone = $event->timezone ?: 'UTC';
        $start = $event->starts_at?->timezone($zone);
        $end = $event->ends_at?->timezone($zone);

        if ($start === null) {
            return 'No date';
        }

        return $start->format('D j M Y, g:ia').($end ? ' – '.($end->isSameDay($start) ? $end->format('g:ia') : $end->format('D j M, g:ia')) : '');
    }

    private static function rendition(?EventImage $image): ?string
    {
        if ($image === null) {
            return null;
        }

        return $image->renditions['display'] ?? $image->path;
    }

    /** @return list<array<string, string|null>> */
    private static function ticketTypes(Event $event): array
    {
        $zone = $event->timezone ?: 'UTC';
        $at = fn (?CarbonInterface $when) => $when?->timezone($zone)->format('j M, g:ia');

        $types = TicketType::query()
            ->where('event_id', $event->id)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $names = $types->pluck('name', 'id');

        return $types->map(fn (TicketType $type) => [
            'name' => $type->name,
            'description' => $type->description,
            'price' => (int) $type->price_amount === 0 ? 'Free' : Money::of((int) $type->price_amount, $event->currency)->format(),
            'quantity' => ($type->quantity_available === null ? 'Unlimited' : number_format((int) $type->quantity_available))
                .($type->max_per_order ? ', up to '.$type->max_per_order.' an order' : ''),
            'window' => match (true) {
                $type->opens_after_id !== null => 'After “'.($names[$type->opens_after_id] ?? 'another ticket').'” sells out',
                $type->sales_start_at === null && $type->sales_end_at === null => 'Until the event',
                default => ($type->sales_start_at ? 'From '.$at($type->sales_start_at) : 'Now').' until '.($type->sales_end_at ? $at($type->sales_end_at) : 'the event'),
            },
            'status' => str_replace('_', ' ', (string) $type->status),
        ])->values()->all();
    }
}
