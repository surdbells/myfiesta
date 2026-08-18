<?php

namespace App\Providers;

use App\Mail\Transport\ZeptoMailTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Laravel ships no ZeptoMail driver, so it is registered as a custom
        // Symfony transport. Everything else — queues, retries, Mailable
        // classes — then works exactly as normal.
        Mail::extend('zeptomail', fn (array $config) => new ZeptoMailTransport(
            apiKey: (string) ($config['api_key'] ?? config('services.zeptomail.api_key')),
        ));
    }
}
