<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\Warranty;
use App\Observers\BookingMessageObserver;
use App\Observers\BookingObserver;
use App\Observers\WarrantyObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Telescope cuma di-install lewat "composer require-dev", jadi tidak
        // ada di production (composer install --no-dev). class_exists() di
        // sini aman dipanggil walau package-nya tidak ter-install — beda
        // dengan mendaftarkan App\Providers\TelescopeServiceProvider secara
        // langsung di bootstrap/providers.php, yang akan fatal error karena
        // class itu extends Laravel\Telescope\TelescopeApplicationServiceProvider.
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        Warranty::observe(WarrantyObserver::class);
        Booking::observe(BookingObserver::class);
        BookingMessage::observe(BookingMessageObserver::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
