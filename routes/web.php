<?php

use App\Http\Controllers\ReferralRedirectController;
use Illuminate\Support\Facades\Route;

// Route::view (bukan closure) supaya `php artisan route:cache` bisa dipakai di production.
Route::view('/', 'welcome');

/*
|--------------------------------------------------------------------------
| Link "Ajak Teman" — /r/{code}
|--------------------------------------------------------------------------
| Lihat ReferralRedirectController untuk penjelasan lengkap.
*/
Route::get('/r/{code}', ReferralRedirectController::class)->where('code', '[A-Za-z0-9]{1,10}');
