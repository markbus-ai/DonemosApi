<?php

namespace App\Enums;

/** Role names stored in roles.nombre. */
enum RolNombre: string
{
    case Staff = 'staff';
    case AdminSede = 'admin-sede';
    case AdminZona = 'admin-zona';

    /** Admin roles see statistics and manage the WhatsApp link. */
    public function esAdmin(): bool
    {
        return $this === self::AdminSede || $this === self::AdminZona;
    }
}
