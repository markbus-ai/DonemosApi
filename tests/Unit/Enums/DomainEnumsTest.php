<?php

namespace Tests\Unit\Enums;

use App\Enums\RecordatorioEstado;
use App\Enums\RolNombre;
use App\Enums\TurnoEstado;
use App\Enums\WarningCode;
use PHPUnit\Framework\TestCase;

class DomainEnumsTest extends TestCase
{
    public function test_only_a_pending_turno_changes_state(): void
    {
        foreach (TurnoEstado::cases() as $destino) {
            $this->assertTrue(TurnoEstado::Pendiente->puedePasarA($destino));
        }

        $this->assertTrue(TurnoEstado::Atendido->puedePasarA(TurnoEstado::Atendido));
        $this->assertFalse(TurnoEstado::Atendido->puedePasarA(TurnoEstado::Cancelado));
        $this->assertFalse(TurnoEstado::Cancelado->puedePasarA(TurnoEstado::Pendiente));
    }

    public function test_active_message_states_exclude_failed(): void
    {
        $this->assertSame(['PENDIENTE', 'ENVIANDO', 'ENVIADO'], RecordatorioEstado::activos());
    }

    public function test_admin_roles(): void
    {
        $this->assertTrue(RolNombre::AdminSede->esAdmin());
        $this->assertTrue(RolNombre::AdminZona->esAdmin());
        $this->assertFalse(RolNombre::Staff->esAdmin());
    }

    public function test_warning_labels_keep_the_audited_wording_and_unknown_codes_stay_raw(): void
    {
        $this->assertSame('Hemoglobina baja', WarningCode::HemoglobinaBaja->label());
        $this->assertSame('Plaquetas no habilitadas', WarningCode::labelFor('PLAQUETAS_NO_HABILITADAS'));
        $this->assertSame('CODIGO_NUEVO', WarningCode::labelFor('CODIGO_NUEVO'));
        $this->assertSame('Autorización extraordinaria', WarningCode::describir([]));
        $this->assertSame(
            'Diferimiento activo + Supera límite anual',
            WarningCode::describir([['code' => 'BLOQUEO_DIFERIMIENTO'], ['code' => 'WARNING_LIMITE_ANUAL']]),
        );
    }
}
