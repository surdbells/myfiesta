<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\Actions\EventActions;
use App\Filament\Resources\Events\Actions\ReviewActions;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Schemas\EventReviewSheet;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\Event;
use App\Services\Events\EventSnapshot;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * One event, laid out for a decision: everything a buyer will see, who is
 * asking, what changed since it was last approved, and the two buttons.
 *
 * Every member of staff can open it; administrators and support decide
 * (ReviewActions). Opened for an event that is no longer waiting — taken back
 * by the organizer, or decided by a colleague a minute ago — it says so in
 * its status and offers no buttons.
 */
class ReviewEvent extends ViewRecord
{
    protected static string $resource = EventResource::class;

    /**
     * The fingerprint (EventSnapshot) of the event as this page first showed
     * it. A decision is made on that: if the organizer takes the event back,
     * changes it and sends it again while the page is open, approving here is
     * refused until the reviewer reloads and looks at what it says now.
     * Locked, so only the server sets it.
     */
    #[Locked]
    public ?string $seen = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->seen = EventSnapshot::fingerprint(EventSnapshot::of($this->event()));
    }

    public function getTitle(): string
    {
        return 'Review: '.$this->event()->title;
    }

    public function getBreadcrumb(): string
    {
        return 'Review';
    }

    /** The record, as the event it always is on this page. */
    private function event(): Event
    {
        $record = $this->getRecord();

        abort_unless($record instanceof Event, 404);

        return $record;
    }

    public function infolist(Schema $schema): Schema
    {
        return EventReviewSheet::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        $record = $this->event();

        return [
            ReviewActions::approve(fn (): ?string => $this->seen)->after(fn () => $this->getRecord()->refresh()),
            ReviewActions::reject(fn (): ?string => $this->seen)->after(fn () => $this->getRecord()->refresh()),

            EventActions::publicPage(),

            Action::make('organizer')
                ->label('Organizer')
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->color('gray')
                ->visible(fn () => $record->organization !== null)
                ->url(fn () => OrganizationResource::getUrl('view', ['record' => $record->organization_id])),

            Action::make('event')
                ->label('Sales and history')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn () => EventResource::getUrl('view', ['record' => $record])),
        ];
    }
}
