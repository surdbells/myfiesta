<?php

namespace App\Filament\Resources\HelpVideos\Pages;

use App\Filament\Resources\HelpVideos\HelpVideoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHelpVideos extends ListRecords
{
    protected static string $resource = HelpVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a video'),
        ];
    }
}
