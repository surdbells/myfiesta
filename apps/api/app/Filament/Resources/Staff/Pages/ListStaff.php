<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Enums\PlatformRole;
use App\Filament\Resources\Staff\StaffResource;
use App\Services\Staff\StaffAccess;
use App\Services\Staff\StaffChangeRefused;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Granting a role to somebody who already has an account.
             *
             * By address, because that is what a colleague can tell you. The
             * account must exist and be verified: the sign-in code goes to
             * that address, and an address nobody has proven they read is not
             * one to send the keys to. If they already have a role, this
             * changes it, under the same rules as "Change role".
             */
            Action::make('grant')
                ->label('Grant access')
                ->icon(Heroicon::OutlinedUserPlus)
                ->modalHeading('Give somebody staff access')
                ->modalDescription('They need a myFiesta account with a verified email address. They sign in here with their usual password and a code emailed to that address. Recorded in the audit trail under your name.')
                ->schema([
                    TextInput::make('email')
                        ->label('Their email address')
                        ->email()
                        ->required()
                        ->maxLength(255),

                    Radio::make('platform_role')
                        ->label('Role')
                        ->options(StaffResource::roleOptions())
                        ->descriptions(StaffResource::roleDescriptions())
                        ->required(),
                ])
                ->modalSubmitActionLabel('Grant access')
                ->action(function (array $data, Action $action): void {
                    $role = PlatformRole::from($data['platform_role']);

                    try {
                        $user = app(StaffAccess::class)->grant($data['email'], $role, auth()->user());
                    } catch (StaffChangeRefused $refused) {
                        Notification::make()
                            ->title('Access not granted')
                            ->body($refused->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }

                    Notification::make()
                        ->title($user->name.' now has the '.$role->label().' role')
                        ->body('They can sign in at '.url('/admin').'.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
