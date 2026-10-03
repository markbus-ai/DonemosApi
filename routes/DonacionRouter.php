<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DonacionController;

Route::get('/donaciones', [DonacionController::class, 'index']);
Route::post('/donaciones', [DonacionController::class, 'store']);
