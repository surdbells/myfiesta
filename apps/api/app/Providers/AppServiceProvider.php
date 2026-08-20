<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
            \Intervention\Image\ImageManager::class,
            fn () => new \Intervention\Image\ImageManager(
                new \Intervention\Image\Drivers\Gd\Driver
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The reset link points at the console, not at this API.
         *
         * Laravel's default builds a URL for a Blade route that does not exist
         * here — the form somebody types their new password into is an Angular
         * screen on another origin. Without this the email arrives with a link
         * to a 404, which is the kind of break nobody notices until a real
         * person is locked out.
         */
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(
            fn ($user, string $token) => rtrim(config('app.console_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset()),
        );
    }
}
