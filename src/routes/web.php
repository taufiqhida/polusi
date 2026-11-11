<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PredictController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/data', [DashboardController::class, 'records'])->name('records');
Route::get('/data/{observation}', [DashboardController::class, 'show'])->name('show');
Route::get('/prediksi', PredictController::class)->name('predict');