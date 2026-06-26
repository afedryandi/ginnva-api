<?php

use App\Http\Controllers\Api\ProductInquiryController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WarrantyController;
use Illuminate\Support\Facades\Route;

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