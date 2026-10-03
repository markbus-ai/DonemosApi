<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RestriccionController;

Route::post('/pacientes/{dni}/restriccion', [RestriccionController::class, 'store']);
Route::delete('/pacientes/{dni}/restriccion', [RestriccionController::class, 'destroy']);
