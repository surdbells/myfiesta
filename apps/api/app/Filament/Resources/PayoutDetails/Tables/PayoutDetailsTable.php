<?php

namespace App\Filament\Resources\PayoutDetails\Tables;

use App\Filament\Support\Listing;
use App\Models\OrganizationPayoutDetail;
use App\Services\Payouts\OwnOrganization;
use App\Services\Payouts\PayoutVerificationRefused;
use App\Services\Payouts\PayoutVerifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
    public const RAILS = ['interac' => 'Interac e-Transfer', 'bank_transfer' => 'Bank transfer'];

    public static function configure(Table $table): Table
    {
        return Listing::defaults($table, 'payout destinations')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization:id,name', 'verifier:id,name']))
            // Encrypted columns are not searchable, and would not be offered if
            // they were: the organization is how anybody asks about these.
            ->searchPlaceholder('Organization')
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('destination')
                    ->label('Pays to')
                    ->state(fn (OrganizationPayoutDetail $record) => $record->maskedDestination()),

                TextColumn::make('rail')
                    ->label('Rail')
                    ->formatStateUsing(fn (string $state) => self::RAILS[$state] ?? $state)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('currency')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('verified_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (OrganizationPayoutDetail $record) => $record->isVerified() ? 'Verified' : 'Not verified')
                    ->color(fn (string $state) => $state === 'Verified' ? 'success' : 'warning')
                    ->description(fn (OrganizationPayoutDetail $record) => $record->isVerified()
                        ? 'by '.($record->verifier?->name ?? 'a former staff member').', '.$record->verified_at->diffForHumans()
                        : null)
                    ->sortable(),

                TextColumn::make('verification_method')
                    ->label('Confirmed by')
                    ->formatStateUsing(fn (?string $state) => $state ? (OrganizationPayoutDetail::VERIFICATION_METHODS[$state] ?? $state) : null)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                // When the organizer last changed them. A destination edited
                // yesterday, just before a large balance, is the one to look at.
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->since()
                    ->dateTimeTooltip('j M Y, H:i')
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

                Listing::currency(),

                SelectFilter::make('rail')
                    ->label('Rail')
                    ->options(self::RAILS),

                Listing::dateRange('changed', 'updated_at', 'Last changed'),
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
            // Somebody on the organization's own team may not verify its
            // details, so the details are not opened for them either.
            ->disabled(fn (OrganizationPayoutDetail $record) => OwnOrganization::includesCurrentUser($record->organization_id))
            ->tooltip(fn (OrganizationPayoutDetail $record) => OwnOrganization::includesCurrentUser($record->organization_id) ? OwnOrganization::VERIFY_DETAILS : null)
            ->modalHeading(fn (OrganizationPayoutDetail $record) => 'Payout details for '.$record->organization?->name)
            ->modalDescription('Opening this is logged. Confirm the details with the organizer through a channel they did not just give you — never a phone number or address from the same change.')
            ->modalSubmitActionLabel('Mark verified')
            ->fillForm(function (OrganizationPayoutDetail $record, Action $action): array {
                try {
                    return app(PayoutVerifier::class)->reveal($record, auth()->user(), request()->ip())
                        + ['seen' => $record->fingerprint()];
                } catch (PayoutVerificationRefused $refused) {
                    Notification::make()->title('Not opened')->body($refused->getMessage())->danger()->send();

                    $action->cancel();
                }
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
            ->modalHeading(fn (OrganizationPayoutDetail $record) => 'Withdraw the verification of '.($record->organization->name ?? 'this organization').'’s payout details?')
            ->modalDescription('Manual payouts to these details will need a stated reason until they are verified again. Recorded in the audit trail under your name.')
            ->modalSubmitActionLabel('Withdraw verification')
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
