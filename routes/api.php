<?php

use App\Http\Controllers\Api\CarouselController;
use App\Http\Controllers\Api\FeaturedProductController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\CaseStudyController;
use App\Http\Controllers\Api\JobOpeningController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\GiiasPartnerSignupController;
use App\Http\Controllers\Api\PartnerSignupController;
use App\Http\Controllers\Api\PartnershipInquiryController;
use App\Http\Controllers\Api\ProductInquiryController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\BookingController;
use App\Http\Controllers\Api\Customer\BookingMessageController;
use App\Http\Controllers\Api\Customer\MyWarrantyController;
use App\Http\Controllers\Api\Customer\StoreReviewController;
use App\Http\Controllers\Api\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Api\Staff\BookingController as StaffBookingController;
use App\Http\Controllers\Api\Staff\BookingMessageController as StaffBookingMessageController;
use App\Http\Controllers\Api\Staff\InventoryController as StaffInventoryController;
use App\Http\Controllers\Api\Staff\AssetController as StaffAssetController;
use App\Http\Controllers\Api\Staff\RawMaterialController as StaffRawMaterialController;
use App\Http\Controllers\Api\Staff\ConsumableItemController as StaffConsumableItemController;
use App\Http\Controllers\Api\Staff\MaterialMemoController as StaffMaterialMemoController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\Partner\PartnerController;

Route::get('/carousels', [CarouselController::class, 'index']);
Route::get('/featured-products', [FeaturedProductController::class, 'index']);
Route::get('/featured-products/all', [FeaturedProductController::class, 'all']);
Route::get('/materials', [MaterialController::class, 'index']);

// Layar login terpadu mobile app — deteksi email ini staff atau customer
// SEBELUM proses login sesungguhnya dimulai.
Route::post('/auth/detect-role', [StaffAuthController::class, 'detectRole'])->middleware('throttle:20,1');

Route::prefix('warranty')->group(function () {
    Route::post('/submit', [WarrantyController::class, 'submit'])->middleware('throttle:10,1');
    Route::get('/check', [WarrantyController::class, 'check'])->middleware('throttle:30,1');
    Route::get('/download/{code}', [WarrantyController::class, 'download'])->middleware('throttle:20,1');
    Route::middleware(['auth:customer', 'throttle:10,1'])->post('/claim', [WarrantyController::class, 'claim']);
});

Route::prefix('quotation')->group(function () {
    Route::get('/options', [QuotationController::class, 'options'])->middleware('throttle:30,1');
    Route::post('/submit', [QuotationController::class, 'submit'])->middleware('throttle:5,1');
});

Route::prefix('inquiry')->group(function () {
    Route::post('/submit', [ProductInquiryController::class, 'submit'])->middleware('throttle:5,1');
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

// "Become a Partner" di halaman /giias — beda dari /partnership/submit di
// atas: akun Partner + kode referral dibuat LANGSUNG real-time, tidak
// lewat review admin (lihat catatan di GiiasPartnerSignupController).
Route::post('/giias/partner-signup', [GiiasPartnerSignupController::class, 'submit'])->middleware('throttle:5,1');

// Dipanggil server-to-server oleh Google Apps Script (lihat catatan di
// GiiasPartnerSignupController::lookup) — throttle lebih longgar karena
// tiap submit form klaim customer di /giias yang bawa referral code
// bakal manggil ini sekali.
Route::get('/giias/partner-lookup/{code}', [GiiasPartnerSignupController::class, 'lookup'])->middleware('throttle:60,1');

// Landing page umum /partner — kembaran /giias di atas tapi TIDAK
// terikat 1 event, link referral yang dihasilkan mengarah ke /partner
// (bukan /giias). Lihat catatan lengkap di PartnerSignupController.
Route::post('/partner-signup', [PartnerSignupController::class, 'submit'])->middleware('throttle:5,1');
Route::get('/partner-lookup/{code}', [PartnerSignupController::class, 'lookup'])->middleware('throttle:60,1');

// Chatbot — publik, tidak wajib login
Route::post('/chat', [ChatController::class, 'send'])->middleware('throttle:20,1');

// Katalog reward Partnership Referral — sama untuk partner maupun customer.
Route::get('/rewards', [RewardController::class, 'index']);

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
            // Wajib per kebijakan Google Play — lihat catatan di
            // CustomerAuthController::deleteAccount().
            Route::delete('/account', [CustomerAuthController::class, 'deleteAccount']);
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

        // Review internal (hybrid: sentimen + tag + komentar opsional) —
        // terpisah dari review Google Maps, lihat StoreReviewController.
        Route::post('/bookings/{id}/review', [StoreReviewController::class, 'store'])
            ->middleware('throttle:10,1');

        // Galeri pemasangan personal — foto dari booking milik customer sendiri
        Route::get('/my-gallery', [BookingMessageController::class, 'gallery']);

        // Partnership Referral — redeem reward pakai poin loyalty
        Route::post('/rewards/{id}/redeem', [RewardController::class, 'redeemAsCustomer'])
            ->middleware('throttle:10,1');
        Route::get('/redemptions', [PointController::class, 'redemptions']);

        // Voucher fisik yang sudah di-assign staff ke akun ini — read-only,
        // tidak ada lagi klaim self-service dari app.
        Route::get('/vouchers', [VoucherController::class, 'myVouchers']);
    });

    // Daftar kampanye voucher yang sedang berjalan (section "Promo") — DI
    // LUAR auth:customer dengan sengaja: customer yang BELUM login pun
    // boleh lihat promo ini, supaya terdorong datang ke toko.
    Route::get('/vouchers/available', [VoucherController::class, 'availableVouchers']);
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
        // Approve booking pending -> confirmed langsung dari app (Store
        // Manager/Direksi/Super Admin) — dicek kapasitas slot instalasi
        // toko, lihat Booking::fullDatesInRange().
        // Preview tanggal kerja + slot terpakai per tanggal, dasar buat
        // form input kapasitas per tanggal di app sebelum approve.
        Route::get('/bookings/{id}/capacity-preview', [StaffBookingController::class, 'capacityPreview']);
        Route::post('/bookings/{id}/confirm', [StaffBookingController::class, 'confirm'])
            ->middleware('throttle:20,1');
        // Batalkan booking (pending/confirmed) langsung dari app.
        Route::post('/bookings/{id}/cancel', [StaffBookingController::class, 'cancel'])
            ->middleware('throttle:20,1');
        // Tandai booking selesai + input kode referral & nominal transaksi
        // (kalau customer datang lewat kode partner) — lihat ReferralPointService.
        Route::post('/bookings/{id}/complete', [StaffBookingController::class, 'complete'])
            ->middleware('throttle:20,1');

        // Kirim pengingat maintenance/servis berkala manual (WA+Push+Email) —
        // lihat ServiceReminderService. Throttle lebih ketat dari aksi lain
        // karena tiap panggilan langsung mengirim pesan WhatsApp nyata.
        Route::post('/bookings/{id}/maintenance-reminder', [StaffBookingController::class, 'sendMaintenanceReminder'])
            ->middleware('throttle:10,1');

        // Assign teknisi & direksi pemantau chat (Store Manager/Direksi
        // saja — installer tidak boleh mengatur assignment).
        Route::get('/bookings/{id}/assignable-staff', [StaffBookingController::class, 'assignableStaff']);
        Route::put('/bookings/{id}/installers', [StaffBookingController::class, 'assignInstallers'])
            ->middleware('throttle:20,1');
        Route::put('/bookings/{id}/watchers', [StaffBookingController::class, 'assignWatchers'])
            ->middleware('throttle:20,1');

        Route::get('/bookings/{id}/messages', [StaffBookingMessageController::class, 'index']);
        Route::post('/bookings/{id}/messages', [StaffBookingMessageController::class, 'store']);

        // Link anonymous token (didaftarkan saat app pertama kali dibuka)
        // ke akun staff yang baru login — supaya push notif booking/chat
        // sampai ke HP admin toko, bukan cuma customer.
        Route::post('/notifications/link-token', [NotificationController::class, 'linkTokenStaff'])
            ->middleware('throttle:20,1');

        // Sistem inventaris — scan QR kardus/barang fisik untuk lihat
        // detail + catat keluar/masuk. Dibatasi ke staff yang akun
        // Filament-nya dicentang akses menu "Barang" (lihat
        // InventoryController::authorizeScan()) — diatur dari checklist
        // "Akses Menu" di form User, bukan role hardcode.
        // Pencarian manual (nama/kode) — buat staff yang belum bisa scan
        // QR langsung di tempat (mis. mencatat dari jarak jauh).
        Route::get('/inventory', [StaffInventoryController::class, 'index']);
        Route::get('/inventory/{code}', [StaffInventoryController::class, 'show']);
        Route::post('/inventory/{code}/movement', [StaffInventoryController::class, 'storeMovement'])
            ->middleware('throttle:30,1');
        Route::post('/inventory/{code}/mark-scroll-code-used', [StaffInventoryController::class, 'markScrollCodeUsed'])
            ->middleware('throttle:30,1');
        Route::post('/inventory/{code}/record-usage', [StaffInventoryController::class, 'recordUsage'])
            ->middleware('throttle:30,1');

        // Aset — scan QR sama seperti Barang, dibatasi ke staff yang
        // akun Filament-nya dicentang akses menu "Aset".
        Route::get('/assets', [StaffAssetController::class, 'index']);
        Route::get('/assets/{code}', [StaffAssetController::class, 'show']);
        Route::post('/assets/{code}/update', [StaffAssetController::class, 'update'])
            ->middleware('throttle:30,1');

        // Bahan Baku — TIDAK ada kode fisik per unit (beda dari Barang &
        // Aset), jadi dicari lewat nama, bukan scan QR. Dibatasi ke staff
        // yang akun Filament-nya dicentang akses menu "Bahan Baku".
        Route::get('/materials', [StaffRawMaterialController::class, 'index']);
        Route::get('/materials/{id}', [StaffRawMaterialController::class, 'show']);
        Route::post('/materials/{id}/movement', [StaffRawMaterialController::class, 'storeMovement'])
            ->middleware('throttle:30,1');
        Route::post('/materials/{id}/adjust', [StaffRawMaterialController::class, 'adjustStock'])
            ->middleware('throttle:30,1');

        // Barang Habis Pakai — sama pola dengan Bahan Baku (tidak ada
        // kode fisik per unit, dicari lewat nama/kode, bukan scan QR).
        Route::get('/consumables', [StaffConsumableItemController::class, 'index']);
        Route::get('/consumables/{id}', [StaffConsumableItemController::class, 'show']);
        Route::post('/consumables/{id}/movement', [StaffConsumableItemController::class, 'storeMovement'])
            ->middleware('throttle:30,1');
        Route::post('/consumables/{id}/adjust', [StaffConsumableItemController::class, 'adjustStock'])
            ->middleware('throttle:30,1');

        // Memo Pengambilan/Pengembalian Barang — 1 memo per instalasi,
        // gabungkan pencatatan keluar-masuk Bahan Baku, Barang Habis
        // Pakai, dan pemakaian meter PPF/WF jadi satu form (bukan buka
        // menu satu-satu). Dibatasi ke staff yang akun Filament-nya
        // dicentang akses menu "Memo Pengambilan/Pengembalian".
        Route::get('/memos', [StaffMaterialMemoController::class, 'index']);
        Route::post('/memos', [StaffMaterialMemoController::class, 'store'])
            ->middleware('throttle:30,1');
        Route::get('/memos/{id}', [StaffMaterialMemoController::class, 'show']);
        Route::post('/memos/{id}/items', [StaffMaterialMemoController::class, 'addItem'])
            ->middleware('throttle:30,1');
        Route::post('/memos/{id}/items/{itemId}/return', [StaffMaterialMemoController::class, 'returnItem'])
            ->middleware('throttle:30,1');
        Route::patch('/memos/{id}/items/{itemId}', [StaffMaterialMemoController::class, 'updateItem'])
            ->middleware('throttle:30,1');
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
    Route::get('/referrals', [PartnerController::class, 'referrals']);
    Route::put('/profile', [PartnerController::class, 'updateProfile']);
    Route::post('/change-password', [PartnerController::class, 'changePassword']);
    Route::get('/promos', [CarouselController::class, 'partnerPromos']);
    Route::get('/notifications', [NotificationController::class, 'partnerHistory']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'partnerMarkRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'partnerMarkAllRead']);
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