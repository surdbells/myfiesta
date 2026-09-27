<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Actions\ImpersonateOrganizationAction;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Organizations\Actions\OrganizationActions;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record->name.($record instanceof Organization && $record->isSuspended() ? ' (suspended)' : '');
    }

    /**
     * Its events and its payout requests, opening as their own lists
     * filtered to it; staff acting as it, reusing the one impersonation
     * action; and suspending it or lifting that, re-read after each so the
     * page shows the new state without a reload.
     */
    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Action::make('events')
                ->label('Events')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(EventResource::getUrl('index', ['filters' => ['organization' => ['value' => $record->getKey()]]])),

            Action::make('payoutRequests')
                ->label('Payout requests')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('gray')
                ->visible(fn () => PayoutRequestResource::canViewAny())
                ->url(PayoutRequestResource::getUrl('index', ['filters' => [
                    'organization' => ['value' => $record->getKey()],
                    'status' => ['value' => null],
                ]])),

            ImpersonateOrganizationAction::make(),

            OrganizationActions::suspend()->after(fn () => $this->getRecord()->refresh()),
            OrganizationActions::unsuspend()->after(fn () => $this->getRecord()->refresh()),
        ];
    }
}
