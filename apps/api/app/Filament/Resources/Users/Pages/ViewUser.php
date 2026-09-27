<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\Actions\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Re-read after each one, so a deactivation shows as one without a reload.
     *
     * Plus a way to every order placed under this address, signed in or not:
     * the table below lists only orders the account itself placed, and the
     * guest checkout somebody is ringing about is very often the other kind.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('ordersByAddress')
                ->label('Orders under this address')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->visible(fn (User $record) => ! str_ends_with(strtolower($record->email), '@erased.invalid'))
                ->url(fn (User $record) => OrderResource::getUrl('index', ['search' => $record->email])),

            ActionGroup::make(array_map(
                fn (Action $action) => $action->after(fn () => $this->getRecord()->refresh()),
                UserActions::all(),
            ))
                ->label('Account')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button(),
        ];
    }
}
