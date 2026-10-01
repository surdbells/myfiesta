<?php

namespace App\Filament\Resources\HelpVideos\Schemas;

use App\Models\HelpVideo;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One how-to video.
 *
 * Whether it is published is not on the form: publishing puts it on a public
 * page, so it is a button of its own on the list and on this page, and asks
 * first. A new video is a draft.
 */
class HelpVideoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The video')
                ->description(
                    'Upload it to the myFiesta YouTube channel first, then paste its address here. '
                    .'The help page shows the thumbnail and plays it from youtube-nocookie.com only when somebody presses play.'
                )
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Buying a ticket on your phone'),

                    TextInput::make('youtube_id')
                        ->label('YouTube address or id')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('https://youtu.be/…')
                        ->helperText('A watch, youtu.be, shorts or embed address, or the 11-character id on its own. Only the id is kept.')
                        // Kept as the id alone, whatever was pasted, so the
                        // page builds the address itself on the no-cookie
                        // domain.
                        ->dehydrateStateUsing(fn (?string $state) => HelpVideo::idFrom($state))
                        ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                            if (HelpVideo::idFrom(is_string($value) ? $value : null) === null) {
                                $fail('That is not a YouTube video address. Paste the address from the video’s Share button, or its 11-character id.');
                            }
                        }),

                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('One or two sentences under the title on the page. Optional.'),
                ]),

            Section::make('Where it goes')
                ->schema([
                    Select::make('audience')
                        ->label('Shown under')
                        ->required()
                        ->options(HelpVideo::AUDIENCES)
                        ->native(false),

                    TextInput::make('sort_order')
                        ->label('Order')
                        ->integer()
                        ->minValue(0)
                        ->maxValue(9999)
                        ->default(0)
                        ->required()
                        ->helperText('Lower comes first. Videos with the same number go by title.'),
                ])
                ->columns(2),
        ]);
    }
}
