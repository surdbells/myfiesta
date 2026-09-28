<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ReviewEvent;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\Schemas\EventInfolist;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\ReadOnlyForStaff;
use App\Models\Event;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * Every event, from every organizer.
 *
 * Organizers build and edit their events in the console; nothing about an
 * event's content is edited here. What the platform does is feature events on
 * the front page and, when one should not be on sale, take it down — with a
 * reason the organizer is sent, and without deleting anything — and looks at
 * each one before it first goes on sale (the review queue and ReviewEvent).
 */
class EventResource extends Resource
{
    use ReadOnlyForStaff;

    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    /** Deleted events too, so a question about one can still be answered. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * An event's page by its id or by its slug.
     *
     * Links the panel builds carry the slug, because that is the event's
     * route key everywhere else. An id still opens the page, so a link from
     * the audit trail or a filter keeps working after the organizer renames
     * the event and its slug changes.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $query = static::getRecordRouteBindingEloquentQuery();

        if ($modifyQuery) {
            $query = $modifyQuery($query) ?? $query;
        }

        return $query
            ->where(Str::isUuid((string) $key) ? 'events.id' : 'events.slug', (string) $key)
            ->first();
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'city', 'slug'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Starts' => $record->starts_at?->timezone($record->timezone)->format('j M Y'),
            'Status' => EventsTable::statusOf($record),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'view' => ViewEvent::route('/{record}'),
            // Everything a buyer will see, laid out for a decision (EventReviews).
            'review' => ReviewEvent::route('/{record}/review'),
        ];
    }
}
