<?php

namespace App\Enums;

enum TurnoEstado: string
{
    case Pendiente = 'PENDIENTE';
    case Atendido = 'ATENDIDO';
    case Cancelado = 'CANCELADO';

    /** Only a pending turno can be resolved; attended and cancelled are final. */
    public function puedePasarA(self $destino): bool
    {
        return $this === $destino || $this === self::Pendiente;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
