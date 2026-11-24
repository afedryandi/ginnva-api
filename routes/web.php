<?php

use App\Http\Controllers\AdminTourController;
use App\Http\Controllers\ReferralRedirectController;
use App\Http\Controllers\WebPushController;
use Illuminate\Support\Facades\Route;

// Route::view (bukan closure) supaya `php artisan route:cache` bisa dipakai di production.
Route::view('/', 'welcome');

// Tanda tur pengenalan panel admin selesai/dilewati (GET idempoten untuk akun sendiri; lihat AdminTourController).
Route::middleware('auth:web')->get('/admin-tour/selesai', [AdminTourController::class, 'done'])->name('admin-tour.done');

/*
|--------------------------------------------------------------------------
| Link "Ajak Teman" — /r/{code}
|--------------------------------------------------------------------------
| Lihat ReferralRedirectController untuk penjelasan lengkap.
*/
Route::get('/r/{code}', ReferralRedirectController::class)->where('code', '[A-Za-z0-9]{1,10}');

// PWA panel admin (2026-11-24): langganan push web perangkat untuk akun yang login di panel.
Route::middleware('auth:web')->prefix('web-push')->name('web-push.')->group(function () {
    Route::post('/subscribe', [WebPushController::class, 'subscribe'])->middleware('throttle:30,1')->name('subscribe');
    Route::post('/unsubscribe', [WebPushController::class, 'unsubscribe'])->middleware('throttle:30,1')->name('unsubscribe');
    Route::post('/test', [WebPushController::class, 'test'])->middleware('throttle:6,1')->name('test');
});
