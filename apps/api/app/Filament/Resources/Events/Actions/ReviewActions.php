<?php

namespace App\Filament\Resources\Events\Actions;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\Outcome;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventReviews;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * Deciding an event waiting for review: approve it onto sale, or send it back
 * with a reason.
 *
 * Administrators and support (EventReviews::mayDecide). Finance reads the
 * queue and the review page and sees neither button. Both confirm by naming
 * the event, both are recorded in the audit trail, and pressing either twice
 * does nothing the second time — the service reads the event again, locked,
 * and a decision already made is not made again or emailed again.
 *
 * Both are given what the page showed ($seen, the event's fingerprint when it
 * opened), and the service refuses a decision on an event that has changed
 * since: the reviewer decides what they looked at, or looks again.
 */
final class ReviewActions
{
    public static function review(): Action
    {
        return Action::make('reviewEvent')
            ->label('Review')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('warning')
            ->visible(fn (Event $record) => $record->status === 'in_review' && $record->deleted_at === null)
            ->url(fn (Event $record) => EventResource::getUrl('review', ['record' => $record]));
    }

    /** @param  Closure(): ?string  $seen  the fingerprint of the event as the page showed it */
    public static function approve(Closure $seen): Action
    {
        return Action::make('approveEvent')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(fn () => EventReviews::currentMayDecide())
            ->visible(fn (Event $record) => $record->status === 'in_review' && $record->deleted_at === null)
            // Somebody who sent it for review themselves is told so on the
            // button, not after confirming. EventReviews refuses it as well.
            ->disabled(fn (Event $record) => self::ownSubmission($record))
            ->tooltip(fn (Event $record) => self::ownSubmission($record) ? EventReviews::OWN_SUBMISSION : null)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedCheckCircle)
            ->modalHeading(fn (Event $record) => 'Approve '.$record->title.'?')
            // The organizer may have set a time for it to go on sale
            // (events:go-live): approved before then, it waits for it.
            ->modalDescription(fn (Event $record) => $record->publish_at?->isFuture()
                ? 'It goes on sale by itself at the time the organizer set, '
                    .$record->publish_at->timezone($record->timezone)->format('l j F Y, g:ia T').', at '
                    .rtrim((string) config('app.public_url'), '/').'/'.$record->slug.'. '
                    .($record->organization->name ?? 'The organizer').' is emailed that it is approved, and people who follow them hear about it when it goes on sale.'
                : 'It goes on sale straight away, at '
                    .rtrim((string) config('app.public_url'), '/').'/'.$record->slug.'. '
                    .($record->organization->name ?? 'The organizer').' is emailed that it is live, and people who follow them hear about it the first time it goes on sale.')
            ->modalSubmitActionLabel(fn (Event $record) => $record->publish_at?->isFuture() ? 'Approve' : 'Approve and put on sale')
            ->action(fn (Event $record, Action $action) => Outcome::run(
                $action,
                'Approved',
                fn (User $staff) => match (true) {
                    app(EventReviews::class)->approve($record, $staff, $seen() ?? '') === 'published' => 'It is on sale, and the organizer has been emailed.',
                    $record->publish_at?->isFuture() === true => 'It goes on sale at the time the organizer set. The organizer has been emailed.',
                    default => 'The organization is suspended, so it goes on sale when that is lifted. The organizer has been emailed.',
                },
            ));
    }

    private static function ownSubmission(Event $record): bool
    {
        $staff = auth()->user();

        return app(EventReviews::class)->isOwnSubmission($record, $staff instanceof User ? $staff : null);
    }

    /** @param  Closure(): ?string  $seen  the fingerprint of the event as the page showed it */
    public static function reject(Closure $seen): Action
    {
        return Action::make('rejectEvent')
            ->label('Reject')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize(fn () => EventReviews::currentMayDecide())
            ->visible(fn (Event $record) => $record->status === 'in_review' && $record->deleted_at === null)
            ->modalIcon(Heroicon::OutlinedArrowUturnLeft)
            ->modalHeading(fn (Event $record) => 'Send '.$record->title.' back to '.($record->organization->name ?? 'the organizer').'?')
            ->modalDescription('It goes back to a draft so they can change it, and does not go on sale. They are emailed your reason exactly as you write it, and the console shows it on the event until they send it again.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason, for the organizer')
                    ->required()
                    ->minLength(EventReviews::MIN_REASON)
                    ->maxLength(EventReviews::MAX_REASON)
                    ->rows(5)
                    ->helperText('Sent as written. Say what is wrong and what would fix it — "The poster is from last year’s event; upload this year’s" rather than "Poster".'),
            ])
            ->modalSubmitActionLabel('Reject and send the reason')
            ->action(fn (Event $record, array $data, Action $action) => Outcome::run(
                $action,
                'Sent back to the organizer',
                function (User $staff) use ($record, $data, $seen): string {
                    app(EventReviews::class)->reject($record, $staff, (string) $data['reason'], $seen() ?? '');

                    return 'It is a draft again, and the organizer has been emailed your reason.';
                },
            ));
    }
}
