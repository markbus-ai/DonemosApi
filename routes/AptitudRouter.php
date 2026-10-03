<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AptitudController;

Route::patch('/pacientes/{dni}/aptitud', [AptitudController::class, 'update']);
