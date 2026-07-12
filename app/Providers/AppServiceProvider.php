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
    public function register(): void {}

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
