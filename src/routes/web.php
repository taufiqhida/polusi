<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PredictController;
use Illuminate\Support\Facades\Route;

// Halaman utama ada di /id (contoh: https://polusi.skaladigital.my/id)
Route::redirect('/', '/id');

Route::prefix('id')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/data', [DashboardController::class, 'records'])->name('records');
    Route::get('/data/{observation}', [DashboardController::class, 'show'])->name('show');
    Route::get('/prediksi', PredictController::class)->name('predict');
});
