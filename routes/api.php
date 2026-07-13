<?php

use App\Http\Controllers\Api\CarouselController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\CaseStudyController;
use App\Http\Controllers\Api\JobOpeningController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\PartnershipInquiryController;
use App\Http\Controllers\Api\ProductInquiryController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\BookingController;
use App\Http\Controllers\Api\Customer\BookingMessageController;
use App\Http\Controllers\Api\Customer\MyWarrantyController;
use App\Http\Controllers\Api\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Api\Staff\BookingController as StaffBookingController;
use App\Http\Controllers\Api\Staff\BookingMessageController as StaffBookingMessageController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\Partner\PartnerController;

Route::get('/carousels', [CarouselController::class, 'index']);
Route::get('/materials', [MaterialController::class, 'index']);

// Layar login terpadu mobile app — deteksi email ini staff atau customer
// SEBELUM proses login sesungguhnya dimulai.
Route::post('/auth/detect-role', [StaffAuthController::class, 'detectRole'])->middleware('throttle:20,1');

Route::prefix('warranty')->group(function () {
    Route::post('/submit', [WarrantyController::class, 'submit'])->middleware('throttle:10,1');
    Route::get('/check', [WarrantyController::class, 'check'])->middleware('throttle:30,1');
    Route::get('/download/{code}', [WarrantyController::class, 'download'])->middleware('throttle:20,1');
    Route::middleware('auth:customer')->post('/claim', [WarrantyController::class, 'claim']);
});

Route::prefix('quotation')->group(function () {
    Route::get('/options', [QuotationController::class, 'options'])->middleware('throttle:30,1');
    Route::post('/submit', [QuotationController::class, 'submit'])->middleware('throttle:5,1');
});

Route::prefix('inquiry')->group(function () {
    Route::post('/submit', [ProductInquiryController::class, 'submit'])->middleware('throttle:5,1');
    // Index hanya untuk admin — tidak boleh publik
    Route::middleware('auth:sanctum')->get('/', [ProductInquiryController::class, 'index']);
});

Route::prefix('stores')->group(function () {
    Route::get('/', [StoreController::class, 'index']);
    Route::get('/{id}', [StoreController::class, 'show']);
    Route::get('/{id}/blocked-dates', [StoreController::class, 'blockedDates']);
});

Route::prefix('news')->group(function () {
    Route::get('/', [NewsController::class, 'index']);
    Route::get('/{slug}', [NewsController::class, 'show']);
});

Route::get('/case-studies', [CaseStudyController::class, 'index']);

Route::get('/job-openings', [JobOpeningController::class, 'index']);

// Baru: pengajuan kemitraan/franchise (我要加盟) — publik, tidak wajib
// login (lihat catatan di PartnershipInquiryController).
Route::post('/partnership/submit', [PartnershipInquiryController::class, 'submit'])->middleware('throttle:5,1');

// Chatbot — publik, tidak wajib login
Route::post('/chat', [ChatController::class, 'send'])->middleware('throttle:20,1');

// Katalog reward Partnership Referral — sama untuk partner maupun customer.
Route::get('/rewards', [RewardController::class, 'index']);

// Voucher promo (mis. "Voucher Rp10jt — 200 pembeli pertama") — publik
// biar bisa nampilin banner sebelum tahu user login atau tidak.
Route::get('/vouchers/active', [VoucherController::class, 'active']);

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
        Route::post('/request-otp', [CustomerAuthController::class, 'requestOtp'])->middleware('throttle:5,1');
        Route::post('/verify-otp', [CustomerAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

        Route::middleware('auth:customer')->group(function () {
            Route::get('/me', [CustomerAuthController::class, 'me']);
            Route::put('/profile', [CustomerAuthController::class, 'updateProfile']);
            Route::post('/logout', [CustomerAuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:customer')->group(function () {
        // 我的质保 — Garansi Saya
        Route::get('/warranties', [MyWarrantyController::class, 'index']);
    Route::get('/warranties/{id}', [MyWarrantyController::class, 'show']);

        // 我的预约 — Booking Saya
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);

        // Chat + progress tracking per booking (polling, bukan real-time)
        Route::get('/bookings/{id}/messages', [BookingMessageController::class, 'index']);
        Route::post('/bookings/{id}/messages', [BookingMessageController::class, 'store']);

        // Galeri pemasangan personal — foto dari booking milik customer sendiri
        Route::get('/my-gallery', [BookingMessageController::class, 'gallery']);

        // Partnership Referral — redeem reward pakai poin loyalty
        Route::post('/rewards/{id}/redeem', [RewardController::class, 'redeemAsCustomer'])
            ->middleware('throttle:10,1');
        Route::get('/redemptions', [PointController::class, 'redemptions']);

        // Voucher promo — klaim & lihat voucher milik sendiri
        Route::post('/vouchers/{id}/claim', [VoucherController::class, 'claim'])
            ->middleware('throttle:5,1');
        Route::get('/vouchers', [VoucherController::class, 'myVouchers']);
    });
});

/*
|--------------------------------------------------------------------------
| Staff (mobile app) — akun admin toko / super_admin, akun SAMA dengan
| yang dipakai login Filament (App\Models\User), guard 'api' (JWT).
|--------------------------------------------------------------------------
*/
Route::prefix('staff')->group(function () {
    Route::post('/auth/login', [StaffAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware('auth:api')->group(function () {
        Route::get('/auth/me', [StaffAuthController::class, 'me']);
        Route::post('/auth/logout', [StaffAuthController::class, 'logout']);

        Route::get('/bookings', [StaffBookingController::class, 'index']);
        Route::get('/bookings/{id}', [StaffBookingController::class, 'show']);
        // Tandai booking selesai + input kode referral & nominal transaksi
        // (kalau customer datang lewat kode partner) — lihat ReferralPointService.
        Route::post('/bookings/{id}/complete', [StaffBookingController::class, 'complete'])
            ->middleware('throttle:20,1');

        Route::get('/bookings/{id}/messages', [StaffBookingMessageController::class, 'index']);
        Route::post('/bookings/{id}/messages', [StaffBookingMessageController::class, 'store']);

        // Link anonymous token (didaftarkan saat app pertama kali dibuka)
        // ke akun staff yang baru login — supaya push notif booking/chat
        // sampai ke HP admin toko, bukan cuma customer.
        Route::post('/notifications/link-token', [NotificationController::class, 'linkTokenStaff'])
            ->middleware('throttle:20,1');
    });
});

/*
|--------------------------------------------------------------------------
| Partner (mobile app) — akun mitra referral. Login LEWAT ENDPOINT STAFF
| di atas (/api/auth/detect-role + /api/staff/auth/login) — akun sama-sama
| dari tabel users, guard 'api', bedanya cuma role 'partner'. Endpoint di
| bawah ini spesifik untuk data profil/poin/reward milik partner tsb.
|--------------------------------------------------------------------------
*/
Route::prefix('partner')->middleware('auth:api')->group(function () {
    Route::get('/me', [PartnerController::class, 'me']);
    Route::get('/points', [PartnerController::class, 'points']);
    Route::get('/redemptions', [PartnerController::class, 'redemptions']);
    Route::post('/rewards/{id}/redeem', [RewardController::class, 'redeemAsPartner'])
        ->middleware('throttle:10,1');
});

// Push Notification token management
// Register/update token — bisa dipanggil guest maupun logged-in customer
Route::post('/notifications/register-token', [NotificationController::class, 'registerToken'])
    ->middleware('throttle:20,1');

// Link anonymous token ke customer setelah login
Route::middleware('auth:customer')->group(function () {
    Route::post('/notifications/link-token', [NotificationController::class, 'linkToken'])
        ->middleware('throttle:20,1');
    Route::get('/customer/notifications', [NotificationController::class, 'history']);
    Route::post('/customer/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/customer/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/customer/points', [PointController::class, 'index']);
});