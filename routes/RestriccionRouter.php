<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RestriccionController;

Route::post('/pacientes/{paciente:dni}/restriccion', [RestriccionController::class, 'store']);
Route::delete('/pacientes/{paciente:dni}/restriccion', [RestriccionController::class, 'destroy']);
