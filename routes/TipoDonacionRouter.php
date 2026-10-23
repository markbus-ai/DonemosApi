<?php

use App\Http\Controllers\TipoDonacionController;
use Illuminate\Support\Facades\Route;

Route::get('/tipos-donacion', [TipoDonacionController::class, 'index']);
