<?php

use App\Http\Controllers\AutoexclusionController;
use App\Http\Controllers\DonacionController;
use Illuminate\Support\Facades\Route;

Route::get('/donaciones', [DonacionController::class, 'index']);
Route::post('/donaciones', [DonacionController::class, 'store']);

// Confidential self-exclusion keyed by donation number.
Route::post('/donaciones/{numero}/autoexclusion', [AutoexclusionController::class, 'store']);
