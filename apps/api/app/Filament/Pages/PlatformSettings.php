<?php

namespace App\Filament\Pages;

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Settings\PlatformSettings as Settings;
use App\Services\Settings\SellerOfRecord;
use App\Services\StaffSupport\StaffActionRefused;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What the platform charges, who sells, and what a receipt says.
 *
 * Settings that used to wait for a deploy: the service charge in each
 * currency, who the seller of record is, whether the service charge is taxed,
 * whether Quebec's QST is collected, and the registration numbers and legal
 * name printed on receipts. Each starts at what the server's configuration
 * says and changes here.
 *
 * Administrators change them. Finance can read them, because they explain
 * every figure finance is asked about. Every change is in the audit log with
 * what it replaced, and every order keeps its own copy of what applied to it,
 * so saving here never rewrites a receipt somebody already has.
 */
class PlatformSettings extends Page
{
    protected static ?string $title = 'Platform settings';

    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPlatformRole(PlatformRole::Admin, PlatformRole::Finance) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Changes apply to orders placed after you save. Orders already placed keep what applied to them, '
            .'and each change is written to the audit log with what it replaced.';
    }

    public function mount(): void
    {
        $this->form->fill(self::toForm(app(Settings::class)->all()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(! $this->mayChange())
            ->components([
                Section::make('Service charge')
                    ->description(
                        'What the buyer pays the platform on top of the ticket price, as a share of what the '
                        .'organizer earns. It is never taken out of the organizer\'s side, and nothing is charged '
                        .'on a sale at the door.'
                    )
                    ->schema([
                        self::percent('service_charge_cad', 'Canada (CAD)'),
                        self::percent('service_charge_ngn', 'Nigeria (NGN)'),
                    ])
                    ->columns(2),

                Section::make('Who sells the ticket')
                    ->description(
                        'The seller of record is who the buyer is buying from, in law. It decides whose name heads '
                        .'the receipt and whose tax the service charge is. Either way, tax on the tickets is charged at '
                        .'the event\'s rate, collected at checkout and kept out of the organizer\'s payout, as it '
                        .'always has been.'
                    )
                    ->schema([
                        Radio::make('seller_of_record')
                            ->label('Seller of record')
                            ->required()
                            ->options([
                                SellerOfRecord::Organizer->value => 'The organizer',
                                SellerOfRecord::Platform->value => 'The platform',
                            ])
                            ->descriptions([
                                SellerOfRecord::Organizer->value => 'The organizer sells the tickets; the platform sells the buyer a '
                                    .'booking service, which is the service charge. The receipt names the organizer as the seller '
                                    .'of the tickets. The tax on the tickets is the organizer\'s to account for. The service charge '
                                    .'is the platform\'s own sale, and is taxed only when the switch below is on.',
                                SellerOfRecord::Platform->value => 'The platform buys the tickets from the organizer and sells them '
                                    .'to the buyer. The receipt names the platform as the seller of everything on it, with the '
                                    .'platform\'s registration numbers, and the platform accounts for all the tax. The service '
                                    .'charge is part of the price of what it sold, so it is always taxed, whatever the switch below says.',
                            ]),

                        Toggle::make('tax_on_service_charge')
                            ->label('Charge tax on the service charge')
                            ->helperText(
                                'At the event\'s own rate: HST in Ontario, GST (and QST, when collected) in Quebec, VAT in '
                                .'Nigeria. In Canada it is added to the service charge; in Nigeria, where prices include VAT, '
                                .'it is inside it and the buyer pays the same. Always on when the platform is the seller.'
                            ),
                    ]),

                Section::make('Quebec Sales Tax')
                    ->description(
                        'While this is off, a buyer at an event in Quebec pays 5% GST and no QST. Turned on, QST is '
                        .'charged beside GST, on the same price, as its own line on the receipt. Turn it on only once the '
                        .'business is registered with Revenu Québec to collect it.'
                    )
                    ->schema([
                        Toggle::make('qst_enabled')
                            ->label('Collect QST in Quebec'),

                        TextInput::make('qst_rate')
                            ->label('QST rate')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.001')
                            ->suffix('%'),
                    ])
                    ->columns(2),

                Section::make('Registration numbers')
                    ->description(
                        'Printed on receipts under the tax they cover, when the platform charged that tax. Leave one '
                        .'empty until the business has it: an empty number is not printed, and a wrong one on a receipt '
                        .'is worse than none.'
                    )
                    ->schema([
                        TextInput::make('gst_hst_number')
                            ->label('GST/HST number')
                            ->maxLength(64)
                            ->placeholder('123456789 RT0001'),

                        TextInput::make('qst_number')
                            ->label('QST number')
                            ->maxLength(64)
                            ->placeholder('1234567890 TQ0001'),

                        TextInput::make('ng_vat_number')
                            ->label('Nigerian VAT (TIN)')
                            ->maxLength(64),
                    ])
                    ->columns(3),

                Section::make('The platform on receipts')
                    ->description(
                        'The registered legal name and the address post reaches, as receipts print them. Until set '
                        .'here, they are the ones on the public site\'s contact page.'
                    )
                    ->schema([
                        TextInput::make('legal_name')
                            ->label('Legal name')
                            ->maxLength(200)
                            ->columnSpanFull(),

                        Textarea::make('address_ca')
                            ->label('Address in Canada')
                            ->rows(2)
                            ->maxLength(500),

                        Textarea::make('address_ng')
                            ->label('Address in Nigeria')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->columns(2),

                Section::make('Almost sold out')
                    ->description(
                        'When a ticket shows "Almost sold out" on the site, the app and the ticket page: once something '
                        .'has sold and what is left is at or under the larger of the two numbers below. "Only 4 left" '
                        .'names the exact count only at or under the last number, so an organizer\'s sales are never '
                        .'readable off the page. Lists take up to a minute to show a change; checkout always counts again.'
                    )
                    ->schema([
                        TextInput::make('almost_sold_out_percent')
                            ->label('Share of capacity left')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),

                        TextInput::make('almost_sold_out_floor')
                            ->label('Or fewer places than')
                            ->helperText('Keeps a small room from reading as nearly full after one sale.')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->suffix('places'),

                        TextInput::make('only_left_under')
                            ->label('Name the exact count at')
                            ->helperText('0 never names a number.')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->suffix('or fewer'),
                    ])
                    ->columns(3),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                // To the question, not to save(): see confirmSave().
                ->livewireSubmitHandler('confirmSave')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->submit('confirmSave')
                            ->visible($this->mayChange())
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    /**
     * Asked before anything is saved.
     *
     * These settings decide what every buyer on every organizer's events is
     * charged and what their receipt says, from the next order on. The button
     * — and Enter in any box — opens the question; the form is checked first,
     * so a mistake is shown on its field rather than behind a modal.
     */
    public function confirmSave(): void
    {
        if (! $this->mayChange()) {
            abort(403);
        }

        $this->getSchema('form')?->validate();

        $this->mountAction('confirmSave');
    }

    public function confirmSaveAction(): Action
    {
        return Action::make('confirmSave')
            ->requiresConfirmation()
            ->modalHeading('Save the platform settings?')
            ->modalDescription('Service charges, tax on them, the seller of record, what receipts say and the sold-out badges change for every organizer’s events. Orders placed from now on use them; orders already placed keep what they were charged. Recorded in the audit trail under your name.')
            ->modalSubmitActionLabel('Save settings')
            ->action(fn () => $this->save());
    }

    public function save(): void
    {
        $staff = auth()->user();

        if (! $staff instanceof User || ! $this->mayChange()) {
            abort(403);
        }

        try {
            $changes = app(Settings::class)->update(self::fromForm($this->form->getState()), $staff);
        } catch (StaffActionRefused $refused) {
            Notification::make()->title('Not saved')->body($refused->getMessage())->danger()->send();

            return;
        }

        $this->form->fill(self::toForm(app(Settings::class)->all()));

        Notification::make()
            ->title($changes === [] ? 'Nothing had changed' : 'Settings saved')
            ->body($changes === [] ? null : 'Orders placed from now on use them.')
            ->success()
            ->send();
    }

    private function mayChange(): bool
    {
        $user = auth()->user();

        return Settings::allows($user instanceof User ? $user : null);
    }

    private static function percent(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step('0.01')
            ->suffix('%');
    }

    /**
     * Settings as a person types them: percentages rather than basis points.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function toForm(array $settings): array
    {
        return [
            'service_charge_cad' => $settings['service_charge_bps_cad'] / 100,
            'service_charge_ngn' => $settings['service_charge_bps_ngn'] / 100,
            'seller_of_record' => $settings['seller_of_record'],
            'tax_on_service_charge' => (bool) $settings['tax_on_service_charge'],
            'qst_enabled' => (bool) $settings['qst_enabled'],
            'qst_rate' => Settings::ppmToPercent((int) $settings['qst_rate_ppm']),
            'gst_hst_number' => $settings['gst_hst_number'],
            'qst_number' => $settings['qst_number'],
            'ng_vat_number' => $settings['ng_vat_number'],
            'legal_name' => $settings['legal_name'],
            'address_ca' => $settings['address_ca'],
            'address_ng' => $settings['address_ng'],
            'almost_sold_out_percent' => (int) $settings['almost_sold_out_percent'],
            'almost_sold_out_floor' => (int) $settings['almost_sold_out_floor'],
            'only_left_under' => (int) $settings['only_left_under'],
        ];
    }

    /**
     * And back, in the units that multiply money.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function fromForm(array $form): array
    {
        return [
            'service_charge_bps_cad' => (int) round(((float) $form['service_charge_cad']) * 100),
            'service_charge_bps_ngn' => (int) round(((float) $form['service_charge_ngn']) * 100),
            'seller_of_record' => $form['seller_of_record'],
            'tax_on_service_charge' => (bool) ($form['tax_on_service_charge'] ?? false),
            'qst_enabled' => (bool) ($form['qst_enabled'] ?? false),
            'qst_rate_ppm' => Settings::percentToPpm($form['qst_rate']),
            'gst_hst_number' => $form['gst_hst_number'] ?? null,
            'qst_number' => $form['qst_number'] ?? null,
            'ng_vat_number' => $form['ng_vat_number'] ?? null,
            'legal_name' => $form['legal_name'] ?? null,
            'address_ca' => $form['address_ca'] ?? null,
            'address_ng' => $form['address_ng'] ?? null,
            'almost_sold_out_percent' => (int) $form['almost_sold_out_percent'],
            'almost_sold_out_floor' => (int) $form['almost_sold_out_floor'],
            'only_left_under' => (int) $form['only_left_under'],
        ];
    }
}
