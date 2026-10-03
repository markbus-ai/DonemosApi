<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ObservacionController;

Route::post('/pacientes/{dni}/observacion', [ObservacionController::class, 'store']);
Route::delete('/pacientes/{dni}/observacion', [ObservacionController::class, 'destroy']);
