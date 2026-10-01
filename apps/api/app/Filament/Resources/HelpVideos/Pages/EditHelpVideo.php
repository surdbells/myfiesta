<?php

namespace App\Filament\Resources\HelpVideos\Pages;

use App\Filament\Resources\HelpVideos\HelpVideoActions;
use App\Filament\Resources\HelpVideos\HelpVideoResource;
use App\Models\HelpVideo;
use Filament\Resources\Pages\EditRecord;

class EditHelpVideo extends EditRecord
{
    protected static string $resource = HelpVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpVideoActions::publish(),
            HelpVideoActions::unpublish(),
            HelpVideoActions::delete(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var HelpVideo $video */
        $video = $this->getRecord();

        HelpVideoResource::record('help_video.edited', $video, ['published' => $video->published]);
    }
}
