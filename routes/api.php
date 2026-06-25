<?php

use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

Route::prefix('warranty')->group(function () {
    Route::post('/submit', [WarrantyController::class, 'submit']);
    Route::get('/check/{code}', [WarrantyController::class, 'check']);
    Route::get('/download/{code}', [WarrantyController::class, 'download']);
});

Route::prefix('quotation')->group(function () {
    Route::post('/calculate', [QuotationController::class, 'calculate']);
    Route::post('/generate-pdf', [QuotationController::class, 'generatePdf']);
});