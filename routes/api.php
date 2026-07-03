<?php

use App\Http\Controllers\Api\CaseStudyController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\PartnershipInquiryController;
use App\Http\Controllers\Api\ProductInquiryController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\BookingController;
use App\Http\Controllers\Api\Customer\MyWarrantyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;

Route::prefix('warranty')->group(function () {
    Route::post('/submit', [WarrantyController::class, 'submit']);
    Route::get('/check', [WarrantyController::class, 'check']);
    Route::get('/download/{code}', [WarrantyController::class, 'download']);
});

Route::prefix('quotation')->group(function () {
    Route::get('/options', [QuotationController::class, 'options']);
    Route::post('/submit', [QuotationController::class, 'submit']);
});

Route::prefix('inquiry')->group(function () {
    Route::post('/submit', [ProductInquiryController::class, 'submit']);
    Route::get('/', [ProductInquiryController::class, 'index']);
});

Route::prefix('stores')->group(function () {
    Route::get('/', [StoreController::class, 'index']);
    Route::get('/{id}', [StoreController::class, 'show']);
});

Route::prefix('news')->group(function () {
    Route::get('/', [NewsController::class, 'index']);
    Route::get('/{slug}', [NewsController::class, 'show']);
});

Route::get('/case-studies', [CaseStudyController::class, 'index']);

// Baru: pengajuan kemitraan/franchise (我要加盟) — publik, tidak wajib
// login (lihat catatan di PartnershipInquiryController).
Route::post('/partnership/submit', [PartnershipInquiryController::class, 'submit']);

// Chatbot — publik, tidak wajib login
Route::post('/chat', [ChatController::class, 'send']);

/*
|--------------------------------------------------------------------------
| Customer (mobile app) — akun end-customer, TERPISAH dari admin Filament
|--------------------------------------------------------------------------
| Guard 'customer' (lihat config/auth.php). Endpoint di luar grup
| middleware ini publik (request-otp, verify-otp); semua yang butuh tahu
| "siapa yang login" ada DI DALAM grup middleware auth:customer.
*/
Route::prefix('customer')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/request-otp', [CustomerAuthController::class, 'requestOtp']);
        Route::post('/verify-otp', [CustomerAuthController::class, 'verifyOtp']);

        Route::middleware('auth:customer')->group(function () {
            Route::get('/me', [CustomerAuthController::class, 'me']);
            Route::put('/profile', [CustomerAuthController::class, 'updateProfile']);
            Route::post('/logout', [CustomerAuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:customer')->group(function () {
        // 我的质保 — Garansi Saya
        Route::get('/warranties', [MyWarrantyController::class, 'index']);

        // 我的预约 — Booking Saya
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
    });
});

// Push Notification token management
use App\Http\Controllers\Api\NotificationController;

// Register/update token — bisa dipanggil guest maupun logged-in customer
Route::post('/notifications/register-token', [NotificationController::class, 'registerToken']);

// Link anonymous token ke customer setelah login
Route::middleware('auth:customer')->group(function () {
    Route::post('/notifications/link-token', [NotificationController::class, 'linkToken']);
});
