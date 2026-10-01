<?php

namespace App\Filament\Resources\HelpVideos\Tables;

use App\Filament\Resources\HelpVideos\HelpVideoActions;
use App\Filament\Support\Listing;
use App\Models\HelpVideo;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every how-to video, published or not, in the order the page shows them.
 */
class HelpVideosTable
{
    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'how-to videos')
            ->searchPlaceholder('Title')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('audience'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (HelpVideo $video) => $video->youtube_id),

                TextColumn::make('audience')
                    ->label('Shown under')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => HelpVideo::AUDIENCES[$state] ?? $state),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('published')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Changed')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('audience')
                    ->label('Shown under')
                    ->options(HelpVideo::AUDIENCES),

                TernaryFilter::make('published')
                    ->trueLabel('Published')
                    ->falseLabel('Drafts'),
            ])
            ->recordActions([
                EditAction::make(),
                HelpVideoActions::publish(),
                HelpVideoActions::unpublish(),
                HelpVideoActions::delete(),
            ])
            ->toolbarActions([]);
    }
}
