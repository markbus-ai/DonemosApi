<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PacienteController;

Route::get('/pacientes/{dni}', [PacienteController::class, 'show']);
Route::post('/pacientes', [PacienteController::class, 'store']);
Route::patch('/pacientes/{dni}', [PacienteController::class, 'update']);
