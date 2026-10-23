<?php

namespace Tests\Concerns;

use App\Models\Sede;
use App\Models\Usuario;
use App\Models\Zona;
use Laravel\Sanctum\Sanctum;

/**
 * Authenticate requests on the `staff` guard the API routes use.
 */
trait ActsAsStaff
{
    /** Operational staff based at the given sede (default: the flagged default sede). */
    protected function actingAsStaff(?Sede $sede = null): Usuario
    {
        return $this->actingAsUsuario(Usuario::factory()->enSede($sede ?? Sede::porDefecto())->create());
    }

    protected function actingAsAdminSede(?Sede $sede = null): Usuario
    {
        return $this->actingAsUsuario(Usuario::factory()->adminSede($sede)->create());
    }

    protected function actingAsAdminZona(?Zona $zona = null): Usuario
    {
        return $this->actingAsUsuario(Usuario::factory()->adminZona($zona)->create());
    }

    protected function actingAsUsuario(Usuario $usuario): Usuario
    {
        Sanctum::actingAs($usuario, ['*'], 'staff');

        return $usuario;
    }
}
