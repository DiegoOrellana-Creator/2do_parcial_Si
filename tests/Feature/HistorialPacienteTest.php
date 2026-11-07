<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Historial completo de citas en la ficha del paciente.
 */
class HistorialPacienteTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $recepcion;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
    }

    private function cita(string $fecha, string $estado = Cita::ATENDIDA, ?User $paciente = null): Cita
    {
        return Cita::findOrFail($this->insertarCita($paciente ?? $this->paciente, $this->medico, $this->cardiologia, $fecha, '08:00', '08:30', $estado));
    }

    public function test_acceso_por_perfil(): void
    {
        $url = route('pacientes.citas', $this->paciente->id);

        $this->get($url)->assertRedirect(route('login'));
        foreach ([$this->paciente, $this->medico, $this->pacienteDe('otro'), $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get($url)->assertForbidden();
        }
        $this->actingAs($this->recepcion)->get($url)->assertOk();
    }

    public function test_muestra_todas_sus_citas_de_la_mas_reciente_a_la_mas_antigua_y_ninguna_ajena(): void
    {
        $antigua = $this->cita('2026-01-05', Cita::NO_ASISTIO);
        $reciente = $this->cita('2026-09-07');
        $ajena = $this->cita('2026-09-08', Cita::ATENDIDA, $this->pacienteDe('otro'));

        $html = $this->actingAs($this->recepcion)->get(route('pacientes.citas', $this->paciente->id))->assertOk()
            ->assertSee($antigua->codigo)->assertSee($reciente->codigo)->assertDontSee($ajena->codigo)
            ->assertSee('Medico01 Prueba')->assertSee('Cardiología')->assertSee('No asistió')
            ->assertSee(route('citas.show', $reciente))
            ->getContent();

        $this->assertLessThan(strpos($html, $antigua->codigo), strpos($html, $reciente->codigo));
        $this->sinJavascript($html);
    }

    public function test_filtra_por_estado_y_fechas(): void
    {
        $enero = $this->cita('2026-01-05', Cita::NO_ASISTIO);
        $marzo = $this->cita('2026-03-02', Cita::CANCELADA);
        $mayo = $this->cita('2026-05-04');
        $url = fn (array $f) => route('pacientes.citas', [$this->paciente->id, ...$f]);

        $this->actingAs($this->recepcion)->get($url(['estado' => 'Cancelada']))
            ->assertSee($marzo->codigo)->assertDontSee($enero->codigo)->assertDontSee($mayo->codigo);
        $this->actingAs($this->recepcion)->get($url(['desde' => '2026-02-01', 'hasta' => '2026-04-01']))
            ->assertSee($marzo->codigo)->assertDontSee($enero->codigo)->assertDontSee($mayo->codigo);
        // Filtros no válidos se ignoran.
        $this->actingAs($this->recepcion)->get($url(['estado' => 'x', 'desde' => 'y']))
            ->assertSee($enero->codigo)->assertSee($mayo->codigo);
        $this->actingAs($this->recepcion)->get($url(['estado' => 'Confirmada']))->assertSee('No hay citas con esos filtros.');
    }

    public function test_pagina_de_quince_en_quince(): void
    {
        foreach (range(1, 17) as $n) {
            $this->cita(sprintf('2026-01-%02d', $n));
        }

        $this->actingAs($this->recepcion)->get(route('pacientes.citas', $this->paciente->id))->assertSee('Mostrando 1–15 de 17 citas');
        $this->actingAs($this->recepcion)->get(route('pacientes.citas', [$this->paciente->id, 'page' => 2]))->assertSee('Mostrando 16–17 de 17 citas');
    }

    public function test_la_ficha_enlaza_el_historial_cuando_hay_citas(): void
    {
        $url = route('pacientes.citas', $this->paciente->id);

        $this->actingAs($this->recepcion)->get(route('pacientes.show', $this->paciente->id))->assertOk()->assertDontSee('Ver historial completo');

        foreach (range(1, 12) as $n) {
            $this->cita(sprintf('2026-02-%02d', $n));
        }

        $this->actingAs($this->recepcion)->get(route('pacientes.show', $this->paciente->id))
            ->assertSee($url)->assertSee('Ver historial completo (12)')->assertSee('Últimas 10 de 12');
    }
}
