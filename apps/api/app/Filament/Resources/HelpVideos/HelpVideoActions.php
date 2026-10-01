<?php

namespace App\Filament\Resources\HelpVideos;

use App\Filament\Support\Outcome;
use App\Models\HelpVideo;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;

/**
 * Publishing a video, taking it down, and deleting it: on the list and on the
 * video's own page, each asking first by name.
 */
final class HelpVideoActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon('heroicon-o-eye')
            ->color('success')
            ->visible(fn (HelpVideo $record) => ! $record->published && HelpVideoResource::canBeManagedBy(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading(fn (HelpVideo $record) => 'Publish “'.$record->title.'”?')
            ->modalDescription(fn (HelpVideo $record) => 'It goes on the public help page at help/videos under '
                .strtolower(HelpVideo::AUDIENCES[$record->audience] ?? $record->audience)
                .' straight away, for anybody to watch. Recorded in the audit trail under your name.')
            ->modalSubmitActionLabel('Publish it')
            ->action(fn (HelpVideo $record, Action $action) => Outcome::run($action, 'Published', function () use ($record) {
                $record->update(['published' => true]);
                HelpVideoResource::record('help_video.published', $record);

                return 'It is on help/videos now.';
            }));
    }

    public static function unpublish(): Action
    {
        return Action::make('unpublish')
            ->label('Take down')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (HelpVideo $record) => $record->published && HelpVideoResource::canBeManagedBy(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading(fn (HelpVideo $record) => 'Take “'.$record->title.'” off the help page?')
            ->modalDescription('It stops showing on help/videos straight away and is kept here as a draft, to publish again later. Recorded in the audit trail under your name.')
            ->modalSubmitActionLabel('Take it down')
            ->action(fn (HelpVideo $record, Action $action) => Outcome::run($action, 'Taken down', function () use ($record) {
                $record->update(['published' => false]);
                HelpVideoResource::record('help_video.unpublished', $record);
            }));
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (HelpVideo $record) => 'Delete “'.$record->title.'”?')
            ->modalDescription('It is removed from this list, and from help/videos if it is published. The video itself stays on YouTube. Recorded in the audit trail under your name.')
            ->modalSubmitActionLabel('Delete it')
            ->after(fn (HelpVideo $record) => HelpVideoResource::record('help_video.deleted', $record));
    }
}
