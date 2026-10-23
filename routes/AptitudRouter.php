<?php

use App\Http\Controllers\AptitudController;
use Illuminate\Support\Facades\Route;

Route::patch('/pacientes/{paciente:dni}/aptitud', [AptitudController::class, 'update']);
