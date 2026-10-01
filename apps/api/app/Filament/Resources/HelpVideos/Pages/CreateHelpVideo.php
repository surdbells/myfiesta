<?php

namespace App\Filament\Resources\HelpVideos\Pages;

use App\Filament\Resources\HelpVideos\HelpVideoResource;
use App\Models\HelpVideo;
use Filament\Resources\Pages\CreateRecord;

/**
 * A new video, as a draft: nothing shows on the help page until it is
 * published from the list or its own page.
 */
class CreateHelpVideo extends CreateRecord
{
    protected static string $resource = HelpVideoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'published' => false];
    }

    protected function afterCreate(): void
    {
        /** @var HelpVideo $video */
        $video = $this->getRecord();

        HelpVideoResource::record('help_video.created', $video);
    }

    /** To its own page, where it can be checked and then published. */
    protected function getRedirectUrl(): string
    {
        return HelpVideoResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
