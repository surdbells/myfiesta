<?php

namespace App\Filament\Actions;

use App\Models\Organization;
use App\Models\User;
use App\Services\Impersonation\Impersonation;
use App\Services\Impersonation\ImpersonationRefused;
use App\Services\Impersonation\WhileImpersonating;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Js;
use Livewire\Component;

/**
 * Open an organization's console as the organization, from its row.
 *
 * Asks why first — the reason goes into the audit trail with the start, under
 * the staff member's own name — then opens the console in a new tab with a
 * one-minute, single-use code in the link. The console trades the code for a
 * token held only in that tab, so nothing here, and nothing in the staff
 * member's own sign-ins, changes.
 *
 * Administrators and support only; see Impersonation::ROLES for why not
 * finance. The service checks again, so hiding the button is not the guard.
 */
class ImpersonateOrganizationAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'impersonate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Open as organization')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (Organization $record): bool => ! $record->trashed()
                && app(Impersonation::class)->mayImpersonate(auth()->user()))
            ->modalIcon('heroicon-o-eye')
            ->modalHeading(fn (Organization $record): string => 'Open '.$record->name.'’s console')
            ->modalDescription(fn (): string => 'You will see the console as an owner of this organization does, for up to '
                .Impersonation::MINUTES.' minutes, in a new tab. '
                .(WhileImpersonating::seesPayouts(auth()->user()?->platform_role)
                    ? 'Changing payout details or asking for a payout'
                    : 'The payouts screen')
                .', the team, integrations, the brand, refunds, the door, exports, and cancelling or deleting events stay out of reach — '
                .'and so does anything that emails people in the organization’s name: messages, campaigns, the waitlist, '
                .'emailed tickets, and publishing an event for the first time. '
                .'Every change you make, and every request refused, is recorded under your name as myFiesta staff.')
            ->modalSubmitActionLabel('Open console')
            ->schema([
                Textarea::make('reason')
                    ->label('Why are you opening it?')
                    ->required()
                    ->minLength(10)
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Recorded in the audit trail with the session. A ticket reference or a sentence — not a buyer’s personal details.'),
            ])
            ->action(function (Organization $record, array $data, Component $livewire): void {
                $staff = auth()->user();

                if (! $staff instanceof User) {
                    abort(403);
                }

                try {
                    ['url' => $url] = app(Impersonation::class)->start($record, $staff, (string) $data['reason'], request()->ip());
                } catch (ImpersonationRefused $refused) {
                    Notification::make()
                        ->title('Console not opened')
                        ->body($refused->getMessage())
                        ->danger()
                        ->send();

                    $this->halt();

                    return;
                }

                // Straight from the submit click, so the browser treats it as
                // the person's own action rather than a pop-up. noopener: the
                // console gets no handle on this admin tab.
                $livewire->js('window.open('.Js::from($url).', "_blank", "noopener")');

                Notification::make()
                    ->title('Staff session started')
                    ->body('The console should have opened in a new tab. If your browser blocked it, use the button within a minute — the link works once.')
                    ->success()
                    ->persistent()
                    ->actions([
                        Action::make('openConsole')
                            ->label('Open the console')
                            ->button()
                            ->url($url, shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }
}
