<?php

namespace App\Enums;

enum RecordatorioEstado: string
{
    case Pendiente = 'PENDIENTE';
    // Claimed by a running batch: never picked again (see EnviarLoteWhatsApp).
    case Enviando = 'ENVIANDO';
    case Enviado = 'ENVIADO';
    case Fallido = 'FALLIDO';

    /**
     * Estados of a message that is queued, going out or already out.
     *
     * @return array<int, string>
     */
    public static function activos(): array
    {
        return [self::Pendiente->value, self::Enviando->value, self::Enviado->value];
    }
}
