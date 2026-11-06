<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Pago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Modelo de la tabla `pago` y su relación con las citas.
 */
class PagoTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private Cita $cita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        $especialidad = $this->especialidadActiva();
        $idCita = $this->insertarCita($this->pacienteDe('paciente01'), $this->medicoCon('medico01', $especialidad), $especialidad, $this->proximoLunes()->toDateString(), '08:00', '08:30');
        $this->cita = Cita::findOrFail($idCita);
    }

    public function test_el_pago_empieza_pendiente_y_pertenece_a_su_cita(): void
    {
        $id = Pago::create(['id_cita' => $this->cita->id_cita, 'monto' => '250'])->id_pago;
        $pago = Pago::findOrFail($id);

        $this->assertSame(Pago::PENDIENTE, $pago->estado_pago);
        $this->assertSame('250.00', $pago->monto);
        $this->assertNull($pago->fecha_pago);
        $this->assertSame($this->cita->codigo, $pago->cita->codigo);
    }

    public function test_una_cita_conserva_sus_intentos_de_pago_en_orden(): void
    {
        $primero = Pago::create(['id_cita' => $this->cita->id_cita, 'monto' => '250']);
        $segundo = Pago::create(['id_cita' => $this->cita->id_cita, 'monto' => '250', 'estado_pago' => Pago::PAGADO, 'id_transaccion' => 'TX-1', 'fecha_pago' => now()]);

        $this->assertSame([$primero->id_pago, $segundo->id_pago], $this->cita->pagos->pluck('id_pago')->all());
        $this->assertSame('TX-1', $this->cita->pagos->last()->id_transaccion);
        $this->assertDatabaseCount('pago', 2);
        $this->assertSame(2, DB::table('pago')->where('id_cita', $this->cita->id_cita)->count());
    }
}
