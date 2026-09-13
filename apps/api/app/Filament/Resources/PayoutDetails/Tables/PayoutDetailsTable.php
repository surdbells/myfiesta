<?php

namespace App\Filament\Resources\PayoutDetails\Tables;

use App\Models\OrganizationPayoutDetail;
use App\Services\Payouts\PayoutVerificationRefused;
use App\Services\Payouts\PayoutVerifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The payout destination queue.
 *
 * Shows nothing that needs decrypting: the rail, the currency and the last
 * four digits kept in the clear for exactly this. The full details appear
 * only inside the verify action, whose opening is logged.
 */
class PayoutDetailsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization:id,name', 'verifier:id,name']))
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('destination')
                    ->label('Pays to')
                    ->state(fn (OrganizationPayoutDetail $record) => $record->maskedDestination()),

                TextColumn::make('currency')->badge()->color('gray'),

                TextColumn::make('verified_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (OrganizationPayoutDetail $record) => $record->isVerified() ? 'Verified' : 'Not verified')
                    ->color(fn (string $state) => $state === 'Verified' ? 'success' : 'warning')
                    ->description(fn (OrganizationPayoutDetail $record) => $record->isVerified()
                        ? 'by '.($record->verifier?->name ?? 'a former staff member').', '.$record->verified_at->diffForHumans()
                        : null),

                // When the organizer last changed them. A destination edited
                // yesterday, just before a large balance, is the one to look at.
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                // Unverified first by default: that is the queue.
                TernaryFilter::make('verified')
                    ->label('Verified')
                    ->trueLabel('Verified')
                    ->falseLabel('Not verified')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('verified_at'),
                        false: fn (Builder $query) => $query->whereNull('verified_at'),
                        blank: fn (Builder $query) => $query,
                    )
                    ->default(false),
            ])
            ->recordActions([
                self::verify(),
                self::revoke(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Open the details, confirm them, mark them verified.
     *
     * One action rather than "reveal" then "verify", so the details somebody
     * verifies are the ones on their screen: the fingerprint taken when this
     * opens goes back with the form, and a change in between is refused.
     */
    private static function verify(): Action
    {
        return Action::make('verify')
            ->label('Review')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(fn (OrganizationPayoutDetail $record) => ! $record->isVerified())
            ->modalHeading(fn (OrganizationPayoutDetail $record) => 'Payout details for '.$record->organization?->name)
            ->modalDescription('Opening this is logged. Confirm the details with the organizer through a channel they did not just give you — never a phone number or address from the same change.')
            ->modalSubmitActionLabel('Mark verified')
            ->fillForm(function (OrganizationPayoutDetail $record): array {
                $details = app(PayoutVerifier::class)->reveal($record, auth()->user(), request()->ip());

                return $details + ['seen' => $record->fingerprint()];
            })
            ->schema(fn (OrganizationPayoutDetail $record) => [
                ...collect([
                    'rail' => 'Rail',
                    'currency' => 'Currency',
                    'interac_email' => 'Interac email',
                    'account_name' => 'Account holder',
                    'bank_name' => 'Bank',
                    'account_number' => 'Account number',
                    'transit_number' => 'Transit number',
                    'institution_number' => 'Institution number',
                    'bank_code' => 'Bank code',
                ])
                    // Only what applies to this destination; an Interac row
                    // with six empty bank fields reads as missing data.
                    ->filter(fn (string $label, string $field) => in_array($field, ['rail', 'currency'], true)
                        || filled($record->getAttribute($field)))
                    ->map(fn (string $label, string $field) => TextInput::make($field)
                        ->label($label)
                        ->disabled()
                        ->dehydrated(false)
                        ->extraInputAttributes(['class' => 'font-mono']))
                    ->values()
                    ->all(),

                Hidden::make('seen'),

                Select::make('method')
                    ->label('How did you confirm these?')
                    ->required()
                    ->options(collect(OrganizationPayoutDetail::VERIFICATION_METHODS)
                        ->when($record->rail !== 'interac', fn ($o) => $o->except('interac_test_transfer'))
                        ->all()),

                Textarea::make('note')
                    ->label('Note')
                    ->rows(2)
                    ->helperText('What you checked, for whoever looks at this after a payout goes wrong.'),
            ])
            ->action(function (OrganizationPayoutDetail $record, array $data, Action $action) {
                try {
                    app(PayoutVerifier::class)->verify(
                        $record,
                        auth()->user(),
                        $data['method'],
                        $data['note'] ?? null,
                        (string) ($data['seen'] ?? ''),
                    );
                } catch (PayoutVerificationRefused $refused) {
                    Notification::make()->title('Not verified')->body($refused->getMessage())->danger()->send();

                    $action->halt();
                }

                Notification::make()->title('Payout details verified')->success()->send();
            });
    }

    private static function revoke(): Action
    {
        return Action::make('revoke')
            ->label('Withdraw verification')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->visible(fn (OrganizationPayoutDetail $record) => $record->isVerified())
            ->requiresConfirmation()
            ->modalDescription('Manual payouts to these details will need a stated reason until they are verified again.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why?')
                    ->required()
                    ->minLength(10),
            ])
            ->action(function (OrganizationPayoutDetail $record, array $data) {
                try {
                    app(PayoutVerifier::class)->revoke($record, auth()->user(), $data['reason']);
                } catch (PayoutVerificationRefused $refused) {
                    Notification::make()->title('Not changed')->body($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Verification withdrawn')->success()->send();
            });
    }
}
