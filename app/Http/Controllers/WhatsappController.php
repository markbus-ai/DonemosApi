<?php

namespace App\Http\Controllers;

use App\Services\Whatsapp\WhatsappVinculacion;
use Illuminate\Http\Request;

/**
 * WhatsApp linking for turno messages. Admin roles only: the QR grants access
 * to the hemocentro's WhatsApp account.
 */
class WhatsappController
{
    public function __construct(
        private WhatsappVinculacion $vinculacion
    ) {}

    public function estado(Request $request)
    {
        $this->autorizar($request);

        return response()->json($this->vinculacion->estado());
    }

    public function vincular(Request $request)
    {
        $this->autorizar($request);

        abort_if($this->vinculacion->enCurso(), 409, 'Ya hay una vinculación en curso; escanee el QR actual.');

        return response()->json($this->vinculacion->iniciar(), 202);
    }

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()->puedeVerReportes(), 403, 'Solo un administrador puede vincular WhatsApp.');
    }
}
