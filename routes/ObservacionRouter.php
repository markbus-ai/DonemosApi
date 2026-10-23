<?php

use App\Http\Controllers\ObservacionController;
use Illuminate\Support\Facades\Route;

Route::post('/pacientes/{dni}/observacion', [ObservacionController::class, 'store']);
// Legacy per-patient removal (desktop client): drops the latest active observation.
Route::delete('/pacientes/{dni}/observacion', [ObservacionController::class, 'destroy']);

// Removal of one specific observation by id.
Route::delete('/observaciones/{observacion}', [ObservacionController::class, 'destroyById']);
Route::delete('/pacientes/{paciente:dni}/observaciones/{observacion}', [ObservacionController::class, 'destroyForPaciente']);
