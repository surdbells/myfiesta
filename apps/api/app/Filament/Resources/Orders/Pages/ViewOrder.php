<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Order '.$this->getRecord()->reference;
    }

    /** Re-read after each one, so a refund's new status is what the page shows. */
    protected function getHeaderActions(): array
    {
        return array_map(
            fn (Action $action) => $action->after(fn () => $this->getRecord()->refresh()),
            OrderActions::all(),
        );
    }
}
