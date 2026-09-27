<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Middleware\AuthenticateStaff;
use App\Http\Middleware\KeepStaffSignedIn;
use App\Notifications\StaffSignInCode;
use App\Support\Session\StaffSessionHandler;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Password, then a code emailed to the account's verified address,
            // every time. Required for everybody: User::hasEmailAuthentication()
            // has no off switch. See App\Filament\Auth\Login for "keep me
            // signed in", whose cookie is only issued once the code is right.
            ->login(Login::class)
            ->multiFactorAuthentication(
                EmailAuthentication::make()
                    ->codeNotification(StaffSignInCode::class)
                    ->codeExpiryMinutes(StaffSignInCode::EXPIRES_MINUTES),
                isRequired: true,
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // The dashboard is App\Filament\Pages\Dashboard, found here. Naming
            // Filament's own as well would put its welcome screen back at
            // /admin in front of it.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                // Before StartSession: it decides the session's lifetime.
                KeepStaffSignedIn::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Filament's check, plus signing out anybody who has lost their
            // staff role since they signed in. Persistent, so Livewire's
            // requests from a page left open are checked too.
            ->authMiddleware([
                AuthenticateStaff::class,
            ], isPersistent: true);
    }

    public function boot(): void
    {
        /*
         * Signed-in sessions keep the staff lifetime whichever request is
         * reading or collecting them. See StaffSessionHandler: without this,
         * garbage collection run from a webhook post deletes an idle staff
         * session after the ordinary two hours.
         */
        Session::extend('database', fn (Application $app) => new StaffSessionHandler(
            $app['db']->connection($app['config']->get('session.connection')),
            $app['config']->get('session.table'),
            $app['config']->get('session.lifetime'),
            (int) $app['config']->get('session.staff_lifetime'),
            $app,
        ));

        /*
         * Livewire's update endpoint, which every table, filter and action in
         * the admin posts to, with the staff session lifetime in front of the
         * web group. Otherwise each click would re-issue the session cookie
         * with the ordinary two-hour expiry, and a tab left open over lunch
         * would come back "page expired". The admin is the only Livewire user
         * in this app; the path is Livewire's default.
         */
        Livewire::setUpdateRoute(fn (array $handle) => Route::post('/livewire/update', $handle)
            ->middleware([KeepStaffSignedIn::class, 'web'])
            ->name('admin.livewire.update'));
    }
}
