<?php

namespace App\Contracts\Payments;

use RuntimeException;

/**
 * Picks the gateway for a currency.
 *
 * Routing lives here rather than as a conditional at the call site. NGN goes to
 * Paystack and everything else to Stripe today; when a third processor arrives,
 * or Paystack covers a second currency, nothing in checkout changes.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->name()] = $gateway;
    }

    public function forCurrency(string $currency): PaymentGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->supports($currency)) {
                return $gateway;
            }
        }

        // Loud rather than silent. A currency with no gateway means an event
        // has been published that cannot take money, and the useful moment to
        // learn that is at checkout, not in a support ticket.
        throw new RuntimeException(
            "No payment gateway handles {$currency}. ".
            'Register one, or stop events being published in this currency.'
        );
    }

    public function named(string $name): PaymentGateway
    {
        return $this->gateways[$name]
            ?? throw new RuntimeException("Unknown payment gateway '{$name}'.");
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }
}
