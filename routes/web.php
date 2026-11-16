<?php

use App\Http\Controllers\AdminTourController;
use App\Http\Controllers\ReferralRedirectController;
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

// Cetak PDF satu data dari menu panel admin mana pun (tombol "Cetak PDF" di halaman lihat/ubah). Akses mengikuti hak lihat menu.
Route::get('/cetak-pdf/{slug}/{record}', \App\Http\Controllers\RecordPdfController::class)->name('record-pdf');
