<?php

use App\Http\Controllers\HabilitacionPlaquetaController;
use App\Http\Controllers\PacienteController;
use Illuminate\Support\Facades\Route;

// List must be declared before the {dni} routes so it is never shadowed.
Route::get('/pacientes', [PacienteController::class, 'index']);
Route::get('/pacientes/{dni}', [PacienteController::class, 'show']);
Route::post('/pacientes', [PacienteController::class, 'store']);
Route::patch('/pacientes/{dni}', [PacienteController::class, 'update']);

// Platelet-enable lifecycle. Role deferral: any `auth:staff`, like restricciones.
Route::get('/pacientes/{dni}/habilitacion-plaquetas', [HabilitacionPlaquetaController::class, 'index']);
Route::post('/pacientes/{dni}/habilitacion-plaquetas', [HabilitacionPlaquetaController::class, 'store']);
Route::delete('/pacientes/{dni}/habilitacion-plaquetas', [HabilitacionPlaquetaController::class, 'destroy']);
