<?php

namespace App\Filament\Resources\Events\Actions;

use App\Filament\Support\Outcome;
use App\Models\Event;
use App\Models\User;
use App\Services\StaffSupport\EventModeration;
use App\Services\StaffSupport\StaffAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * Featuring and takedowns: the platform deciding what the public sees.
 *
 * Administrators only. Both are recorded in the audit trail, and a takedown
 * emails the organizer the reason — it never deletes anything.
 */
final class EventActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::feature(),
            self::unfeature(),
            self::takeDown(),
            self::restore(),
        ];
    }

    public static function publicPage(): Action
    {
        return Action::make('publicPage')
            ->label('Open public page')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (Event $record) => $record->status !== 'draft' && $record->deleted_at === null)
            ->url(fn (Event $record) => rtrim((string) config('app.public_url'), '/').'/'.$record->slug, shouldOpenInNewTab: true);
    }

    public static function feature(): Action
    {
        return Action::make('feature')
            ->label('Feature on the front page')
            ->icon(Heroicon::OutlinedStar)
            ->color('warning')
            ->authorize(fn () => StaffAction::current(StaffAction::Feature))
            ->visible(fn (Event $record) => ! $record->is_featured
                && $record->status === 'published'
                && $record->taken_down_at === null
                && $record->deleted_at === null)
            ->requiresConfirmation()
            ->modalDescription('It joins the featured row on the public front page for its city until you unfeature it.')
            ->action(fn (Event $record, Action $action) => Outcome::run(
                $action,
                'Featured',
                fn (User $staff) => app(EventModeration::class)->feature($record, $staff, true),
            ));
    }

    public static function unfeature(): Action
    {
        return Action::make('unfeature')
            ->label('Stop featuring')
            ->icon(Heroicon::OutlinedStar)
            ->color('gray')
            ->authorize(fn () => StaffAction::current(StaffAction::Feature))
            ->visible(fn (Event $record) => (bool) $record->is_featured)
            ->requiresConfirmation()
            ->action(fn (Event $record, Action $action) => Outcome::run(
                $action,
                'No longer featured',
                fn (User $staff) => app(EventModeration::class)->feature($record, $staff, false),
            ));
    }

    public static function takeDown(): Action
    {
        return Action::make('takeDown')
            ->label('Take down')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('danger')
            ->authorize(fn () => StaffAction::current(StaffAction::TakeDown))
            ->visible(fn (Event $record) => $record->taken_down_at === null
                && $record->status !== 'cancelled'
                && $record->deleted_at === null)
            ->modalHeading(fn (Event $record) => 'Take '.$record->title.' off sale?')
            ->modalDescription('The page is hidden and checkout stops. Nothing is deleted: tickets already sold still work, and orders and money are untouched. '
                .'The organizer is emailed the reason below and cannot publish again until an administrator restores it.')
            ->modalSubmitActionLabel('Take down')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason, for the organizer')
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000)
                    ->rows(4)
                    ->helperText('Emailed to the organizer as written. Say what is wrong and what would fix it.'),
            ])
            ->action(fn (Event $record, array $data, Action $action) => Outcome::run(
                $action,
                'Event taken down',
                function (User $staff) use ($record, $data) {
                    $told = app(EventModeration::class)->takeDown($record, $staff, (string) $data['reason']);

                    return $told === 0
                        ? 'Nobody at the organization could be emailed — tell them another way.'
                        : "{$told} ".($told === 1 ? 'person' : 'people').' at the organization emailed.';
                },
            ));
    }

    public static function restore(): Action
    {
        return Action::make('restoreEvent')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->authorize(fn () => StaffAction::current(StaffAction::TakeDown))
            ->visible(fn (Event $record) => $record->taken_down_at !== null && $record->deleted_at === null)
            ->modalHeading('Lift the takedown?')
            ->modalDescription('It goes back on sale if it was on sale before and still can be. Otherwise it stays a draft the organizer can publish. They are emailed either way.')
            ->modalSubmitActionLabel('Restore')
            ->schema([
                Textarea::make('note')->label('Note')->maxLength(1000)->rows(2),
            ])
            ->action(fn (Event $record, array $data, Action $action) => Outcome::run(
                $action,
                'Takedown lifted',
                fn (User $staff) => app(EventModeration::class)->restore($record, $staff, $data['note'] ?? null) === 'published'
                    ? 'Back on sale.'
                    : 'Left as a draft for the organizer to publish.',
            ));
    }
}
