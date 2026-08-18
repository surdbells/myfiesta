<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaystackGateway;
use App\Services\Payments\StripeGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayRegistry::class, function () {
            $registry = new PaymentGatewayRegistry;

            // Order matters only as a tie-break; in practice the currency each
            // adapter claims is disjoint.
            $registry->register(new StripeGateway(
                secretKey: (string) config('payments.stripe.secret_key'),
                webhookSecret: (string) config('payments.stripe.webhook_secret'),
            ));

            $registry->register(new PaystackGateway(
                secretKey: (string) config('payments.paystack.secret_key'),
            ));

            return $registry;
        });
    }
}
