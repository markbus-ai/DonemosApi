<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AptitudController;

Route::patch('/pacientes/{paciente:dni}/aptitud', [AptitudController::class, 'update']);
