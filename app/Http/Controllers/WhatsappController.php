<?php

namespace App\Http\Controllers;

use App\Services\Whatsapp\WhatsappVinculacion;
use Illuminate\Support\Facades\Cache;

/**
 * WhatsApp linking for turno messages. Admin roles only (`administrar-whatsapp`
 * gate on the routes): the QR grants access to the hemocentro's WhatsApp
 * account.
 */
class WhatsappController
{
    private const EN_CURSO = 'Ya hay una vinculación en curso; escanee el QR actual.';

    public function __construct(
        private WhatsappVinculacion $vinculacion
    ) {}

    public function estado()
    {
        return response()->json($this->vinculacion->estado());
    }

    public function vincular()
    {
        // The lock closes the gap between the check and the bridge start, so
        // two clicks cannot launch two bridges on the same session.
        $lock = Cache::lock('whatsapp:vincular', 30);

        abort_unless($lock->get(), 409, self::EN_CURSO);

        try {
            abort_if($this->vinculacion->enCurso(), 409, self::EN_CURSO);

            return response()->json($this->vinculacion->iniciar(), 202);
        } finally {
            $lock->release();
        }
    }
}
