<?php

namespace App\Services\Settings;

use App\Enums\PlatformRole;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\StaffSupport\StaffActionRefused;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The settings staff change in the admin, over the defaults a deploy sets.
 *
 * Every setting has a default from configuration (config/tax.php,
 * config/payments.php, config/myfiesta.php), so nothing here has to exist for
 * the platform to work: a fresh install charges what its environment says.
 * An administrator saving a different value writes a row, and that row wins
 * until it is changed again.
 *
 * Read on every quote, so the stored rows are cached, and the cache is
 * cleared whenever one is written. What each setting was before a change is
 * in the audit log, not here: this table only knows what applies now, and
 * the order keeps its own copy of what applied to it (Pricer).
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.v1';

    /** The currencies a service charge is set for. Anything else falls back to the default. */
    public const CURRENCIES = ['CAD', 'NGN'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * What applies when nobody has chosen otherwise.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $serviceCharge = (int) config('payments.service_charge_bps', 800);

        return [
            'service_charge_bps_cad' => $serviceCharge,
            'service_charge_bps_ngn' => $serviceCharge,
            'seller_of_record' => SellerOfRecord::tryFrom((string) config('tax.seller_of_record'))?->value
                ?? SellerOfRecord::Organizer->value,
            'tax_on_service_charge' => (bool) config('tax.tax_on_service_charge', false),
            'qst_enabled' => (bool) config('tax.qst.enabled', false),
            'qst_rate_ppm' => self::percentToPpm(config('tax.qst.rate', '9.975')) ?? 99750,
            'gst_hst_number' => self::text(config('tax.registration.gst_hst')),
            'qst_number' => self::text(config('tax.registration.qst')),
            'ng_vat_number' => self::text(config('tax.registration.ng_vat')),
            // The same registered name and addresses the legal pages show,
            // until staff give receipts something more specific.
            'legal_name' => self::text(config('myfiesta.contact.company_name')),
            'address_ca' => self::text(config('myfiesta.contact.addresses.CA')),
            'address_ng' => self::text(config('myfiesta.contact.addresses.NG')),
            // When a ticket reads as nearly gone (config/discovery.php).
            'almost_sold_out_percent' => (int) config('discovery.availability.almost_percent', 10),
            'almost_sold_out_floor' => (int) config('discovery.availability.almost_floor', 5),
            'only_left_under' => (int) config('discovery.availability.exact_under', 10),
        ];
    }

    /**
     * Every setting as it applies now.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = $this->stored();
        $values = [];

        foreach ($this->defaults() as $key => $default) {
            // array_key_exists, not ??: a number cleared on purpose is stored
            // as null, and must not fall back to the one it replaced.
            $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** Which settings staff have chosen, rather than left to the default. */
    public function overridden(): array
    {
        return array_keys($this->stored());
    }

    // --- what checkout asks --------------------------------------------------

    /** The service charge for a sale in this currency, in basis points. */
    public function serviceChargeBps(string $currency): int
    {
        $key = 'service_charge_bps_'.strtolower($currency);
        $values = $this->all();

        return (int) ($values[$key] ?? config('payments.service_charge_bps', 800));
    }

    public function sellerOfRecord(): SellerOfRecord
    {
        return SellerOfRecord::tryFrom((string) $this->get('seller_of_record')) ?? SellerOfRecord::Organizer;
    }

    /**
     * Whether the service charge is taxed.
     *
     * Always where the platform is the seller: the service charge is then
     * part of the price of what the platform sold. Where the organizer is,
     * the service charge is the platform's own separate sale to the buyer,
     * and whether it is taxed is the setting's to say.
     */
    public function serviceChargeTaxed(): bool
    {
        return $this->sellerOfRecord() === SellerOfRecord::Platform
            || (bool) $this->get('tax_on_service_charge');
    }

    /** QST in millionths (99750 is 9.975%), or null while it is not collected. */
    public function qstPpm(): ?int
    {
        $values = $this->all();

        return $values['qst_enabled'] ? (int) $values['qst_rate_ppm'] : null;
    }

    /**
     * The platform's registration numbers that cover a sale in this place.
     *
     * Only the ones that exist and apply: a GST/HST number in Canada, a QST
     * number in Quebec while QST is collected, a VAT number in Nigeria.
     *
     * @return list<array{label: string, number: string}>
     */
    public function registrations(string $country, ?string $subdivision): array
    {
        $values = $this->all();

        $numbers = match (strtoupper($country)) {
            'CA' => array_filter([
                'GST/HST' => $values['gst_hst_number'],
                'QST' => strtoupper((string) $subdivision) === 'QC' && $values['qst_enabled']
                    ? $values['qst_number']
                    : null,
            ]),
            'NG' => array_filter(['VAT (TIN)' => $values['ng_vat_number']]),
            default => [],
        };

        return array_values(array_map(
            fn (string $label, string $number) => ['label' => $label, 'number' => $number],
            array_keys($numbers),
            $numbers,
        ));
    }

    public function legalName(): ?string
    {
        return $this->get('legal_name');
    }

    // --- what the badges ask -------------------------------------------------

    /**
     * When a ticket reads as almost sold out, and when its exact count is
     * named (Availability).
     *
     * @return array{percent: int, floor: int, exact_under: int}
     */
    public function scarcity(): array
    {
        $values = $this->all();

        return [
            'percent' => (int) $values['almost_sold_out_percent'],
            'floor' => (int) $values['almost_sold_out_floor'],
            'exact_under' => (int) $values['only_left_under'],
        ];
    }

    /** Where post reaches the platform in this market. */
    public function address(string $country): ?string
    {
        return match (strtoupper($country)) {
            'CA' => $this->get('address_ca'),
            'NG' => $this->get('address_ng'),
            default => null,
        };
    }

    // --- changing them -------------------------------------------------------

    public static function allows(?User $staff): bool
    {
        return $staff !== null
            && $staff->deleted_at === null
            && $staff->hasPlatformRole(PlatformRole::Admin);
    }

    /**
     * Save what an administrator chose, and write down what it replaced.
     *
     * Only what actually changed is written. A form saved with nothing
     * altered writes nothing and records nothing, so the audit log reads as a
     * list of decisions rather than a list of clicks. Keys that are not
     * settings are ignored; keys that are missing are left as they are.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{from: mixed, to: mixed}> what changed
     *
     * @throws StaffActionRefused
     */
    public function update(array $values, User $staff): array
    {
        if (! self::allows($staff)) {
            throw StaffActionRefused::because('Only administrators change platform settings.');
        }

        $current = $this->all();
        $changes = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $current)) {
                continue;
            }

            $value = $this->normalise($key, $value);

            if ($value !== $current[$key]) {
                $changes[$key] = ['from' => $current[$key], 'to' => $value];
            }
        }

        if ($changes === []) {
            return [];
        }

        DB::transaction(function () use ($changes, $staff) {
            foreach ($changes as $key => $change) {
                PlatformSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $change['to'], 'updated_by' => $staff->id],
                );
            }
        });

        // Again after the commit: a quote read between the write and the
        // commit would otherwise have cached the old values for good.
        self::forget();

        $this->auditor->record('platform_settings.changed', actor: $staff, metadata: [
            'changes' => $changes,
        ]);

        return $changes;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A percentage as typed — "9.975" — in millionths.
     *
     * Millionths because Quebec's rate has three decimals and basis points
     * have two; an integer because this multiplies money.
     */
    public static function percentToPpm(mixed $percent): ?int
    {
        if ($percent === null || $percent === '' || ! is_numeric($percent)) {
            return null;
        }

        return (int) round(((float) $percent) * 10000);
    }

    /** Millionths back to the percentage a person reads: 99750 is "9.975". */
    public static function ppmToPercent(int $ppm): string
    {
        return rtrim(rtrim(number_format($ppm / 10000, 4, '.', ''), '0'), '.');
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => PlatformSetting::query()->get(['key', 'value'])->pluck('value', 'key')->all(),
        );
    }

    /**
     * One value in the shape it is kept in, or a refusal saying what is wrong.
     *
     * @throws StaffActionRefused
     */
    private function normalise(string $key, mixed $value): mixed
    {
        return match ($key) {
            'service_charge_bps_cad', 'service_charge_bps_ngn' => $this->whole($value, 0, 10000, 'A service charge is between 0% and 100%.'),
            'qst_rate_ppm' => $this->whole($value, 0, 1_000_000, 'A tax rate is between 0% and 100%.'),
            'almost_sold_out_percent' => $this->whole($value, 0, 100, 'A share of capacity is between 0% and 100%.'),
            'almost_sold_out_floor' => $this->whole($value, 0, 10000, 'The fewest places is a whole number from 0 to 10,000.'),
            'only_left_under' => $this->whole($value, 0, 1000, 'The most places named exactly is a whole number from 0 to 1,000.'),
            'seller_of_record' => SellerOfRecord::tryFrom((string) $value)?->value
                ?? throw StaffActionRefused::because('The seller is either the organizer or the platform.'),
            'tax_on_service_charge', 'qst_enabled' => (bool) $value,
            'address_ca', 'address_ng' => $this->limited($value, 500),
            'legal_name' => $this->limited($value, 200),
            default => $this->limited($value, 64),
        };
    }

    private function whole(mixed $value, int $min, int $max, string $refusal): int
    {
        if (! is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
            throw StaffActionRefused::because($refusal);
        }

        return (int) $value;
    }

    private function limited(mixed $value, int $max): ?string
    {
        $text = self::text($value);

        if ($text !== null && mb_strlen($text) > $max) {
            throw StaffActionRefused::because("That is longer than {$max} characters.");
        }

        return $text;
    }

    private static function text(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
