<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Events\Actions\ReviewActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Organizations\Schemas\OrganizationInfolist;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\EventReview;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every event waiting to go on sale, oldest first.
 *
 * The oldest first because an organizer waiting is an organizer not selling,
 * and the one who has waited longest has the best reason to be annoyed. Each
 * row says how long it has waited and enough about who is asking to know
 * which to open first — whether the organizer is verified, whether they are
 * suspended, whether buyers have disputed their payments before, and whether
 * this event has been sent back already.
 *
 * Every member of staff can read the queue. Administrators and support decide,
 * on the review page each row opens (ReviewEvent).
 */
class EventReviewQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Events to review';

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?string $slug = 'review-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 39;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformStaff() && $user->deleted_at === null;
    }

    /** How many are waiting, beside the name in the navigation. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Event::query()->where('status', 'in_review')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Events waiting for review';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Event::query()
                ->where('status', 'in_review')
                ->with(['organization'])
                ->addSelect([
                    'disputes_count' => Dispute::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('disputes.organization_id', 'events.organization_id'),
                    'rejections_count' => EventReview::query()
                        ->selectRaw('count(*)')
                        ->whereColumn('event_reviews.event_id', 'events.id')
                        ->where('event_reviews.action', EventReview::REJECTED),
                ]))
            ->defaultSort('submitted_at', 'asc')
            ->paginated([25, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Every event sent for review has been decided. New ones appear here, and staff who review are emailed.')
            ->columns([
                TextColumn::make('title')
                    ->label('Event')
                    ->weight('medium')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Event $record) => $record->organization?->name),

                TextColumn::make('submitted_at')
                    ->label('Waiting')
                    ->sortable()
                    ->formatStateUsing(fn (Event $record) => $record->submitted_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE))
                    ->description(fn (Event $record) => $record->submitted_at?->format('j M, H:i').' UTC'),

                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->sortable()
                    ->formatStateUsing(fn (Event $record) => $record->starts_at?->timezone($record->timezone)->format('D j M Y, g:ia'))
                    ->description(fn (Event $record) => collect([$record->city, $record->country])->filter()->implode(', ')),

                TextColumn::make('organizer_verification')
                    ->label('Organizer')
                    ->state(fn (Event $record) => $record->organization
                        ? OrganizationInfolist::verification($record->organization)
                        : 'Deleted')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Verified' ? 'success' : 'gray')
                    ->description(fn (Event $record) => $record->organization?->suspended_at !== null ? 'Suspended' : null),

                TextColumn::make('disputes_count')
                    ->label('Disputes')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : null),

                TextColumn::make('seen_before')
                    ->label('Before')
                    ->state(fn (Event $record) => match (true) {
                        $record->approved_at !== null => 'Approved before, changed since',
                        (int) $record->getAttribute('rejections_count') > 0 => 'Sent back '.((int) $record->getAttribute('rejections_count') === 1 ? 'once' : (int) $record->getAttribute('rejections_count').' times'),
                        default => 'First review',
                    })
                    ->color(fn (string $state) => $state === 'First review' ? 'gray' : 'warning'),
            ])
            ->recordUrl(fn (Event $record) => EventResource::getUrl('review', ['record' => $record]))
            ->recordActions([ReviewActions::review()])
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('events.deleted_at'));
    }
}
