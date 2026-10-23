<?php

use App\Http\Controllers\RestriccionController;
use Illuminate\Support\Facades\Route;

Route::post('/pacientes/{paciente:dni}/restriccion', [RestriccionController::class, 'store']);
Route::delete('/pacientes/{paciente:dni}/restriccion', [RestriccionController::class, 'destroy']);
