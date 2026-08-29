<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\Quotation;
use App\Models\RewardRedemption;
use App\Models\StoreReview;
use App\Models\Warranty;
use App\Observers\BookingMessageObserver;
use App\Observers\BookingObserver;
use App\Observers\QuotationObserver;
use App\Observers\RewardRedemptionObserver;
use App\Observers\StoreReviewObserver;
use App\Observers\WarrantyObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        Quotation::observe(QuotationObserver::class);
        RewardRedemption::observe(RewardRedemptionObserver::class);
        StoreReview::observe(StoreReviewObserver::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // SEBELUMNYA endpoint POST /customer/bookings pakai middleware
        // bawaan throttle:10,1. Middleware itu resolve identitas lewat
        // $request->user() TANPA guard spesifik -- artinya selalu cek
        // guard DEFAULT ('api', dipakai staff/admin, lihat
        // config/auth.php), bukan guard 'customer' yang benar-benar
        // dipakai endpoint ini. Karena request customer tidak pernah
        // lolos guard 'api', $request->user() selalu null, dan limiter
        // diam-diam fallback ke per-IP -- BUKAN per-akun seperti yang
        // dimaksud ("Rate-limit spam booking 10x/menit per akun"). Akun
        // beda yang berbagi jaringan (WiFi kantor/kampus, NAT) jadi
        // saling kena limit bareng, sementara spammer yang gonta-ganti
        // IP/data seluler lolos tanpa batas per akun. Limiter custom ini
        // key eksplisit ke id customer via guard 'customer', fallback ke
        // IP cuma kalau benar-benar belum login. Ditemukan & diperbaiki
        // 2026-08-29.
        RateLimiter::for('customer-booking-submit', function (Request $request) {
            $key = $request->user('customer')?->id ?? $request->ip();

            return Limit::perMinute(10)->by('customer-booking-submit:'.$key);
        });
    }
}
