<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TipoDonacionController;

Route::get('/tipos-donacion', [TipoDonacionController::class, 'index']);
