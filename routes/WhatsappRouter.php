<?php

use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

// Link the hemocentro's WhatsApp (QR) for turno messages; admins only.
Route::middleware('can:administrar-whatsapp')->group(function () {
    Route::get('/whatsapp/estado', [WhatsappController::class, 'estado']);
    Route::post('/whatsapp/vincular', [WhatsappController::class, 'vincular']);
});
