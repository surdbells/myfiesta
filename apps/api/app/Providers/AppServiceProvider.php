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
        //
    }
}
