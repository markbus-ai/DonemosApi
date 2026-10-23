<?php

namespace App\Enums;

enum RecordatorioTipo: string
{
    case Confirmacion = 'CONFIRMACION';
    case Recordatorio = 'RECORDATORIO';
    case Cancelacion = 'CANCELACION';
}
