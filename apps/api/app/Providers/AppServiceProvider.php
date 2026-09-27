<?php

namespace App\Providers;

use App\Support\Preflight;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * GD rather than Imagick, chosen rather than defaulted to.
         *
         * GD ships enabled in every PHP build this will run on, including the
         * Forge default; Imagick is a separate package and a deployment that
         * silently lacks it fails at the first upload rather than at boot.
         * Imagick is better at colour profiles and worse at being present.
         */
        $this->app->singleton(
            ImageManager::class,
            fn () => new ImageManager(
                new Driver
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->refuseToServeMisconfigured();

        /*
         * Per key, not per address: two integrations behind one office NAT
         * are two budgets, and one key spread across servers is one.
         */
        RateLimiter::for('api-key', fn (Request $request) => Limit::perMinute(120)
            ->by('key:'.hash('sha256', (string) $request->bearerToken())));

        /*
         * The reset link points at the console, not at this API.
         *
         * Laravel's default builds a URL for a Blade route that does not exist
         * here — the form somebody types their new password into is an Angular
         * screen on another origin. Without this the email arrives with a link
         * to a 404, which is the kind of break nobody notices until a real
         * person is locked out.
         */
        ResetPassword::createUrlUsing(
            fn ($user, string $token) => rtrim(config('app.console_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset()),
        );
    }

    /**
     * Production does not serve with a secret missing or a sender pretending.
     *
     * See Preflight for the list and why each one matters. A web request that
     * boots into a bad configuration fails here, before a route runs; the
     * worker and the scheduler fail as they start. Every other command is left
     * to run — package:discover and config:cache happen at build time with no
     * secrets at all, and an operator repairing a box needs artisan to work.
     */
    private function refuseToServeMisconfigured(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        if (! $this->app->runningInConsole()) {
            Preflight::enforce();

            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (in_array($event->command, Preflight::SERVING_COMMANDS, true)) {
                Preflight::enforce();
            }
        });
    }
}
