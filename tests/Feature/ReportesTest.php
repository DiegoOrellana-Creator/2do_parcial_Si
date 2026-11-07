<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Especialidad;
use App\Models\Pago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Reportes: exclusivo del administrador, solo consulta; la exportación queda en la bitácora.
 */
class ReportesTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private Especialidad $cardiologia;

    private Especialidad $pediatria;

    private User $medicoA;

    private User $medicoB;

    private User $ana;

    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
        $this->pediatria = $this->especialidadActiva('Pediatría');
        $this->medicoA = $this->medicoCon('medico_a', $this->cardiologia, null);
        $this->medicoB = $this->medicoCon('medico_b', $this->pediatria, null);
        $this->ana = $this->pacienteDe('ana');
        $this->beto = $this->pacienteDe('beto', true);
        DB::table('paciente')->where('id_paciente', $this->beto->id)->update(['inasistencia' => 3]);

        // Marzo de 2026: 6 citas. Abril: 1 (fuera del periodo).
        $this->insertarCita($this->ana, $this->medicoA, $this->cardiologia, '2026-03-02', '08:00', '08:30', 'Atendida');
        $this->insertarCita($this->ana, $this->medicoA, $this->cardiologia, '2026-03-03', '08:00', '08:30', 'Cancelada');
        $this->insertarCita($this->beto, $this->medicoA, $this->cardiologia, '2026-03-04', '08:00', '08:30', 'No asistió');
        $this->insertarCita($this->beto, $this->medicoB, $this->pediatria, '2026-03-05', '08:00', '08:30', 'No asistió');
        $idPagada = $this->insertarCita($this->ana, $this->medicoB, $this->pediatria, '2026-03-06', '08:00', '08:30', 'Atendida');
        $this->insertarCita($this->ana, $this->medicoB, $this->pediatria, '2026-03-31', '08:00', '08:30', 'Programada');
        $this->insertarCita($this->ana, $this->medicoA, $this->cardiologia, '2026-04-01', '08:00', '08:30', 'Atendida');

        Pago::create(['id_cita' => $idPagada, 'monto' => '150', 'estado_pago' => Pago::PAGADO, 'fecha_pago' => '2026-03-06 09:00:00']);
        Pago::create(['id_cita' => $idPagada, 'monto' => '150', 'estado_pago' => Pago::PENDIENTE]);
    }

    private function reporte(array $filtros = ['desde' => '2026-03-01', 'hasta' => '2026-03-31'])
    {
        return $this->actingAs($this->admin->fresh())->get(route('reportes.index', $filtros));
    }

    public function test_solo_el_administrador_ve_los_reportes(): void
    {
        $this->get(route('reportes.index'))->assertRedirect(route('login'));
        $this->get(route('reportes.exportar'))->assertRedirect(route('login'));

        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        foreach ([$recepcion, $this->medicoA, $this->ana, $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get(route('reportes.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('reportes.exportar'))->assertForbidden();
        }
        $this->assertSame(0, Bitacora::where('modulo', 'Reportes')->count());

        $this->sinJavascript($this->reporte()->assertOk()->getContent());
    }

    public function test_resume_las_citas_y_los_ingresos_del_periodo(): void
    {
        $r = $this->reporte()->assertOk();

        $this->assertSame(6, $r->viewData('total'));
        $this->assertSame(['Programada' => 1, 'Confirmada' => 0, 'Atendida' => 2, 'Cancelada' => 1, 'No asistió' => 2], $r->viewData('resumen')->all());
        $this->assertSame(50.0, (float) $r->viewData('tasaAsistencia'));
        $this->assertSame(['cantidad' => 1, 'total' => 150.0], $r->viewData('pagos'));
        $r->assertSee('Bs 150,00')->assertSee('01/03/2026 – 31/03/2026');

        $especialidades = $r->viewData('porEspecialidad')->keyBy('nombre');
        $this->assertSame([3, 1, 1, 1], array_map('intval', [$especialidades['Cardiología']->total, $especialidades['Cardiología']->atendidas, $especialidades['Cardiología']->canceladas, $especialidades['Cardiología']->inasistencias]));
        $this->assertSame([3, 1, 0, 1], array_map('intval', [$especialidades['Pediatría']->total, $especialidades['Pediatría']->atendidas, $especialidades['Pediatría']->canceladas, $especialidades['Pediatría']->inasistencias]));
        $this->assertSame(['Medico_a Prueba', 'Medico_b Prueba'], $r->viewData('porMedico')->pluck('nombre')->sort()->values()->all());

        $inasistentes = $r->viewData('inasistentes');
        $this->assertSame([(int) $this->beto->id], $inasistentes->pluck('id_paciente')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, (int) $inasistentes->first()->total);
        $this->assertSame(1, $r->viewData('bloqueados'));
        $r->assertSee('Bloqueado');
    }

    public function test_filtra_por_especialidad_y_medico(): void
    {
        $r = $this->reporte(['desde' => '2026-03-01', 'hasta' => '2026-03-31', 'especialidad' => $this->pediatria->id_especialidad]);
        $this->assertSame(3, $r->viewData('total'));
        $this->assertSame(['cantidad' => 1, 'total' => 150.0], $r->viewData('pagos'));

        $r = $this->reporte(['desde' => '2026-03-01', 'hasta' => '2026-03-31', 'medico' => $this->medicoA->id]);
        $this->assertSame(3, $r->viewData('total'));
        $this->assertSame(['cantidad' => 0, 'total' => 0.0], $r->viewData('pagos'));
        $this->assertNull($this->reporte(['desde' => '2026-03-03', 'hasta' => '2026-03-03'])->viewData('tasaAsistencia'));
    }

    public function test_sin_fechas_usa_el_mes_actual_y_avisa_si_el_rango_esta_invertido(): void
    {
        $r = $this->reporte([]);
        $this->assertSame(now()->startOfMonth()->toDateString(), $r->viewData('filtros')['desde']);
        $this->assertSame(now()->endOfMonth()->toDateString(), $r->viewData('filtros')['hasta']);

        $this->reporte(['desde' => '2026-02-30', 'hasta' => 'mañana'])->assertOk()->assertViewHas('filtros', fn ($f) => $f['desde'] === now()->startOfMonth()->toDateString());

        $this->reporte(['desde' => '2026-03-31', 'hasta' => '2026-03-01'])->assertOk()
            ->assertSee('La fecha «Desde» es posterior a «Hasta»')->assertViewHas('total', 0)->assertDontSee('Exportar citas');
    }

    public function test_exporta_el_detalle_en_csv_y_lo_registra(): void
    {
        DB::table('citas')->where('fecha_cita', '2026-03-03')->update(['motivo_cancelacion' => '=HYPERLINK("http://x")']);

        $r = $this->actingAs($this->admin->fresh())->get(route('reportes.exportar', ['desde' => '2026-03-01', 'hasta' => '2026-03-31', 'medico' => $this->medicoA->id]));
        $r->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDownload('citas_2026-03-01_a_2026-03-31.csv');

        $csv = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lineas = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertCount(4, $lineas, 'Encabezado y las 3 citas del médico A en marzo');
        $this->assertStringStartsWith('Código;Fecha;', $lineas[0]);
        $this->assertStringContainsString('02/03/2026;08:00;08:30;"Ana Prueba"', $lineas[1]);
        $this->assertStringContainsString("'=HYPERLINK", $lineas[2], 'Un texto no se interpreta como fórmula');
        $this->assertStringNotContainsString('Medico_b', $csv);

        $evento = Bitacora::where('modulo', 'Reportes')->sole();
        $this->assertSame('Exportación', $evento->accion);
        $this->assertSame((int) $this->admin->id, (int) $evento->id_usuario);
        $this->assertSame("Exportación de 3 citas del 01/03/2026 al 31/03/2026, médico #{$this->medicoA->id}.", $evento->detalle);
    }

    public function test_el_menu_y_el_panel_enlazan_reportes_solo_al_administrador(): void
    {
        $this->actingAs($this->admin->fresh())->get(route('dashboard'))->assertOk()
            ->assertSee('href="' . route('reportes.index') . '"', false)->assertSee('Ver reportes');

        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $recepcion->id]);
        $this->actingAs($recepcion->fresh())->get(route('dashboard'))->assertOk()
            ->assertDontSee(route('reportes.index'))->assertDontSee('Ver reportes')->assertDontSee('Generar reporte');
    }
}
