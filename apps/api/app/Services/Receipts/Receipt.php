<?php

namespace App\Services\Receipts;

use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Checkout\TaxLine;
use App\Services\Settings\PlatformSettings;
use App\Services\Settings\SellerOfRecord;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * What an order says about itself as a receipt.
 *
 * Read entirely from the order: its lines, its figures, its tax lines and the
 * copy of the settings it was priced under. Nothing here asks what the rates
 * or the settings are today, so a receipt opened next year says what was
 * charged this year, by whom, and under which registration numbers — even
 * after the rates are superseded and the settings have moved on.
 *
 * Orders from before orders kept that copy are read the way they were made:
 * one tax, the organizer as the seller, no tax on the service charge.
 *
 * Ticket codes are not on it. A receipt is forwarded to an accountant and
 * printed for an expense claim; a code is a way into the event.
 */
final readonly class Receipt
{
    private function __construct(
        public string $reference,
        public CarbonInterface $issuedAt,
        public string $currency,
        public SellerOfRecord $sellerOfRecord,
        /** @var array{name: ?string, address: ?string, registrations: list<array{label: string, number: string}>} who sold the tickets */
        public array $seller,
        /** @var array{name: ?string, address: ?string, registrations: list<array{label: string, number: string}>}|null who sold the service, where that is someone else */
        public ?array $service,
        /** The organizer, named where the platform is the seller. */
        public ?string $organizer,
        /** @var list<array{name: string, quantity: int, unit_price: Money, discount: Money, amount: Money}> */
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        /** @var list<TaxLine> */
        public array $taxes,
        /** The service charge before any tax added to it; with its tax inside where prices include tax. */
        public Money $serviceCharge,
        public Money $total,
        public Money $refunded,
    ) {}

    public static function for(Order $order): self
    {
        $order->loadMissing(['lines', 'event.organization', 'taxRate']);

        $currency = $order->currency;
        $snapshot = $order->pricing_snapshot ?? [];
        $seller = SellerOfRecord::tryFrom((string) $order->seller_of_record) ?? SellerOfRecord::Organizer;
        $taxes = self::taxes($order);

        $organizerName = $snapshot['organizer']['name'] ?? $order->event?->organization?->name;
        $platform = [
            'name' => $snapshot['platform']['name']
                ?? app(PlatformSettings::class)->legalName()
                ?? config('app.name'),
            'address' => $snapshot['platform']['address'] ?? null,
            'registrations' => $snapshot['platform']['registrations'] ?? [],
        ];

        // Tax added to the service charge is shown on its own line under it;
        // tax inside it, the way Nigerian prices hold VAT, stays in the figure
        // and is shown as included.
        $addedToCharge = array_sum(array_map(
            fn (TaxLine $line) => $line->on === TaxLine::ON_SERVICE_CHARGE && ! $line->inclusive ? $line->amount->amount : 0,
            $taxes,
        ));

        return new self(
            reference: $order->reference,
            issuedAt: $order->paid_at ?? $order->created_at ?? now(),
            currency: $currency,
            sellerOfRecord: $seller,
            seller: $seller === SellerOfRecord::Platform
                ? $platform
                : ['name' => $organizerName, 'address' => null, 'registrations' => []],
            service: $seller === SellerOfRecord::Organizer && $order->service_charge_amount > 0 ? $platform : null,
            organizer: $seller === SellerOfRecord::Platform ? $organizerName : null,
            lines: $order->lines
                ->map(fn (OrderLine $line) => [
                    'name' => (string) $line->name,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => new Money((int) $line->unit_price_amount, $currency),
                    'discount' => new Money((int) $line->discount_amount, $currency),
                    'amount' => new Money((int) $line->line_total_amount, $currency),
                ])
                ->values()
                ->all(),
            subtotal: new Money((int) $order->subtotal_amount, $currency),
            discount: new Money((int) $order->discount_amount, $currency),
            taxes: $taxes,
            serviceCharge: new Money((int) $order->service_charge_amount - $addedToCharge, $currency),
            total: new Money((int) $order->total_amount, $currency),
            refunded: new Money((int) $order->refunds()->where('status', 'succeeded')->sum('amount'), $currency),
        );
    }

    /** Whether the prices on it already contain their tax. */
    public function taxIncluded(): bool
    {
        foreach ($this->taxes as $line) {
            if ($line->inclusive) {
                return true;
            }
        }

        return false;
    }

    /** @return list<TaxLine> */
    public function taxesOn(string $on): array
    {
        return array_values(array_filter($this->taxes, fn (TaxLine $line) => $line->on === $on));
    }

    /**
     * For a client: amounts as minor units with their currency, like every
     * other amount the API returns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $money = fn (Money $m) => ['amount' => $m->amount, 'currency' => $m->currency];

        return [
            'reference' => $this->reference,
            'issued_at' => $this->issuedAt->toIso8601String(),
            'currency' => $this->currency,
            'seller_of_record' => $this->sellerOfRecord->value,
            'seller' => $this->seller,
            'service' => $this->service,
            'organizer' => $this->organizer,
            'lines' => array_map(fn (array $line) => [
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_price' => $money($line['unit_price']),
                'discount' => $money($line['discount']),
                'amount' => $money($line['amount']),
            ], $this->lines),
            'subtotal' => $money($this->subtotal),
            'discount' => $money($this->discount),
            'taxes' => array_map(fn (TaxLine $line) => [
                'name' => $line->name,
                'rate' => $line->percent(),
                'on' => $line->on,
                'included' => $line->inclusive,
                'amount' => $money($line->amount),
            ], $this->taxes),
            'service_charge' => $money($this->serviceCharge),
            'total' => $money($this->total),
            'tax_included' => $this->taxIncluded(),
            'refunded' => $money($this->refunded),
        ];
    }

    /**
     * Every tax on the order.
     *
     * From the lines it kept where it kept them. Before that, an order had
     * one tax on its tickets and none on its service charge, and its rate is
     * still on the row it points at — superseded rows keep their rate.
     *
     * Public because the spreadsheet, the webhook and the admin's order page
     * show the same taxes as the receipt, and none of them should have its
     * own idea of how an older order was taxed.
     *
     * @return list<TaxLine>
     */
    public static function taxes(Order $order): array
    {
        if (is_array($order->tax_lines)) {
            return array_map(
                fn (array $line) => TaxLine::fromArray($line, $order->currency),
                $order->tax_lines,
            );
        }

        if ($order->tax_amount <= 0) {
            return [];
        }

        $rate = $order->taxRate;
        $base = $order->tax_inclusive
            ? $order->subtotal_amount - $order->discount_amount
            : $order->net_revenue_amount;

        return [new TaxLine(
            name: $rate?->name ?? 'Tax',
            ratePpm: (int) ($rate?->rate_bps ?? 0) * 100,
            on: TaxLine::ON_TICKETS,
            base: new Money((int) $base, $order->currency),
            amount: new Money((int) $order->tax_amount, $order->currency),
            inclusive: (bool) $order->tax_inclusive,
            taxRateId: $rate?->id,
        )];
    }
}
