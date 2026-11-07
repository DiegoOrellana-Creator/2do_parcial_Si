<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Confirmar citas, registrar asistencia e inasistencias, y bloqueo y desbloqueo del paciente.
 */
class AsistenciaCitaTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $recepcion;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    private string $pasado;

    private string $futuro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->pasado = now()->subWeek()->startOfWeek()->toDateString();
        $this->futuro = $this->proximoLunes()->toDateString();
    }

    private function cita(string $fecha, string $estado = Cita::PROGRAMADA, string $inicio = '08:00', string $fin = '08:30', ?User $paciente = null): Cita
    {
        return Cita::findOrFail($this->insertarCita($paciente ?? $this->paciente, $this->medico, $this->cardiologia, $fecha, $inicio, $fin, $estado));
    }

    private function ficha(): Paciente
    {
        return Paciente::findOrFail($this->paciente->id);
    }

    // --- Acceso --------------------------------------------------------------------

    public function test_solo_quien_gestiona_citas_registra_y_solo_el_administrador_desbloquea(): void
    {
        $pasada = $this->cita($this->pasado);
        $futura = $this->cita($this->futuro);
        $otraPasada = $this->cita($this->pasado, Cita::PROGRAMADA, '08:30', '09:00');
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['bloqueado' => true]);

        $this->patch(route('citas.inasistencia', $pasada))->assertRedirect(route('login'));
        $this->patch(route('pacientes.desbloquear', $this->paciente->id))->assertRedirect(route('login'));

        foreach ([$this->paciente, $this->medico, $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->patch(route('citas.confirmar', $futura))->assertForbidden();
            $this->actingAs($otro)->patch(route('citas.atendida', $pasada))->assertForbidden();
            $this->actingAs($otro)->patch(route('citas.inasistencia', $pasada))->assertForbidden();
            $this->actingAs($otro)->patch(route('pacientes.desbloquear', $this->paciente->id))->assertForbidden();
        }
        $this->assertSame([Cita::PROGRAMADA, 0], [$pasada->fresh()->estado, $this->ficha()->inasistencia]);

        // Recepción registra asistencia, pero no desbloquea.
        $this->actingAs($this->recepcion)->patch(route('citas.atendida', $pasada))->assertSessionHas('status');
        $this->assertSame(Cita::ATENDIDA, $pasada->fresh()->estado);
        $this->actingAs($this->recepcion)->patch(route('pacientes.desbloquear', $this->paciente->id))->assertForbidden();
        $this->assertTrue($this->ficha()->bloqueado);

        $this->actingAs($this->admin->fresh())->patch(route('citas.inasistencia', $otraPasada))->assertSessionHas('status');
        $this->assertSame(Cita::NO_ASISTIO, $otraPasada->fresh()->estado);
    }

    // --- Confirmar ---------------------------------------------------------------------

    public function test_confirma_una_cita_programada_futura(): void
    {
        $cita = $this->cita($this->futuro);

        $this->actingAs($this->recepcion)->patch(route('citas.confirmar', $cita))
            ->assertRedirect(route('citas.show', $cita))->assertSessionHas('status', "Cita {$cita->codigo} confirmada.");

        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_anterior' => 'Programada', 'estado_nuevo' => 'Confirmada', 'registrado_por' => $this->recepcion->id]);
        $evento = Bitacora::sole();
        $this->assertSame(['Cambio de estado', 'Citas', (int) $cita->id_cita], [$evento->accion, $evento->modulo, (int) $evento->id_registro]);

        // Ya confirmada, o pasada, no se confirma otra vez.
        $this->actingAs($this->recepcion)->patch(route('citas.confirmar', $cita))->assertSessionHas('error');
        $pasada = $this->cita($this->pasado);
        $this->actingAs($this->recepcion)->patch(route('citas.confirmar', $pasada))->assertSessionHas('error');
        $this->assertSame(Cita::PROGRAMADA, $pasada->fresh()->estado);
        $this->assertSame(1, Bitacora::count());
    }

    // --- Asistencia -----------------------------------------------------------------------

    public function test_registra_atendida_sin_sumar_inasistencias(): void
    {
        $cita = $this->cita($this->pasado, Cita::CONFIRMADA);

        $this->actingAs($this->recepcion)->patch(route('citas.atendida', $cita))
            ->assertSessionHas('status', "Cita {$cita->codigo} registrada como atendida.");

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertSame(0, $this->ficha()->inasistencia);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_anterior' => 'Confirmada', 'estado_nuevo' => 'Atendida']);
    }

    public function test_no_registra_asistencia_antes_de_la_hora_ni_en_citas_cerradas(): void
    {
        $citas = [
            $this->cita($this->futuro),
            $this->cita($this->pasado, Cita::CANCELADA),
            $this->cita($this->pasado, Cita::ATENDIDA, '08:30', '09:00'),
            $this->cita($this->pasado, Cita::NO_ASISTIO, '09:00', '09:30'),
        ];

        foreach ($citas as $cita) {
            foreach (['citas.atendida', 'citas.inasistencia'] as $ruta) {
                $this->actingAs($this->recepcion)->patch(route($ruta, $cita))
                    ->assertSessionHas('error', 'No se puede registrar la asistencia: la cita debe estar programada o confirmada y haber llegado su hora.');
            }
            $this->assertSame($cita->estado, $cita->fresh()->estado);
        }

        $this->assertSame(0, $this->ficha()->inasistencia);
        $this->assertSame(0, Bitacora::count());
    }

    public function test_cuenta_las_inasistencias_y_una_cita_no_se_cuenta_dos_veces(): void
    {
        $cita = $this->cita($this->pasado);

        $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $cita))
            ->assertSessionHas('status', "Cita {$cita->codigo} registrada como «No asistió». El paciente acumula 1 inasistencia.");
        $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $cita))->assertSessionHas('error');

        $this->assertSame(1, $this->ficha()->inasistencia);
        $this->assertFalse($this->ficha()->bloqueado);
        $this->assertDatabaseCount('bloqueo_paciente', 0);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_nuevo' => 'No asistió', 'registrado_por' => $this->recepcion->id]);
    }

    // --- Bloqueo ----------------------------------------------------------------------------

    public function test_a_la_tercera_inasistencia_queda_bloqueado_y_no_puede_pedir_citas(): void
    {
        foreach ([['08:00', '08:30'], ['08:30', '09:00']] as [$inicio, $fin]) {
            $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $this->cita($this->pasado, Cita::PROGRAMADA, $inicio, $fin)));
        }
        $this->assertSame([2, false], [$this->ficha()->inasistencia, $this->ficha()->bloqueado]);
        $this->assertDatabaseCount('bloqueo_paciente', 0);

        $tercera = $this->cita($this->pasado, Cita::PROGRAMADA, '09:00', '09:30');
        $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $tercera))
            ->assertSessionHas('status', "Cita {$tercera->codigo} registrada como «No asistió». El paciente acumula 3 inasistencias y quedó bloqueado.");

        $this->assertSame([3, true], [$this->ficha()->inasistencia, $this->ficha()->bloqueado]);
        $this->assertDatabaseHas('bloqueo_paciente', ['id_paciente' => $this->paciente->id, 'inasistencias_acumuladas' => 3, 'fecha_desbloqueo' => null, 'motivo' => 'Bloqueo automático por 3 inasistencias.']);
        $this->assertDatabaseHas('bitacora', ['accion' => 'Bloqueo', 'modulo' => 'Pacientes', 'id_registro' => $this->paciente->id, 'id_usuario' => $this->recepcion->id]);

        // No puede solicitar ni recibir citas nuevas.
        $datos = ['id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->futuro, 'franja' => $this->medico->id . '|08:00'];
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $datos)
            ->assertSessionHasErrors(['id_paciente' => 'El paciente está bloqueado y no puede registrar citas.']);
        $this->actingAs($this->recepcion)->post(route('citas.store'), [...$datos, 'id_paciente' => $this->paciente->id])
            ->assertSessionHasErrors('id_paciente');
    }

    public function test_una_inasistencia_mas_no_crea_otro_bloqueo(): void
    {
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['inasistencia' => 3, 'bloqueado' => true]);

        $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $this->cita($this->pasado)))->assertSessionHas('status');

        $this->assertSame(4, $this->ficha()->inasistencia);
        $this->assertDatabaseCount('bloqueo_paciente', 0);
        $this->assertSame(0, Bitacora::where('accion', 'Bloqueo')->count());
    }

    public function test_si_falla_el_historial_no_cambia_nada(): void
    {
        $cita = $this->cita($this->pasado);
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['inasistencia' => 2]);
        \App\Models\HistorialEstadoCita::creating(fn () => throw new \RuntimeException('Fallo simulado.'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $cita));
            $this->fail('Se esperaba el fallo simulado.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo simulado.', $e->getMessage());
        }
        \App\Models\HistorialEstadoCita::flushEventListeners();

        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);
        $this->assertSame([2, false], [$this->ficha()->inasistencia, $this->ficha()->bloqueado]);
        $this->assertDatabaseCount('bloqueo_paciente', 0);
        $this->assertSame(0, Bitacora::count());
    }

    // --- Desbloqueo -------------------------------------------------------------------------

    public function test_el_administrador_desbloquea_y_el_contador_vuelve_a_cero(): void
    {
        foreach ([['08:00', '08:30'], ['08:30', '09:00'], ['09:00', '09:30']] as [$inicio, $fin]) {
            $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $this->cita($this->pasado, Cita::PROGRAMADA, $inicio, $fin)));
        }

        $this->actingAs($this->admin->fresh())->patch(route('pacientes.desbloquear', $this->paciente->id))
            ->assertRedirect(route('pacientes.show', $this->paciente->id))
            ->assertSessionHas('status', 'Paciente desbloqueado. Su contador de inasistencias volvió a 0.');

        $this->assertSame([0, false], [$this->ficha()->inasistencia, $this->ficha()->bloqueado]);
        $this->assertNotNull(DB::table('bloqueo_paciente')->where('id_paciente', $this->paciente->id)->value('fecha_desbloqueo'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'Desbloqueo', 'id_registro' => $this->paciente->id, 'id_usuario' => $this->admin->id]);
        // Las citas se conservan como historial.
        $this->assertSame(3, Cita::where('estado', Cita::NO_ASISTIO)->count());

        // Ya puede solicitar citas, y desbloquear de nuevo no hace nada.
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), [
            'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->futuro, 'franja' => $this->medico->id . '|08:00',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin->fresh())->patch(route('pacientes.desbloquear', $this->paciente->id))
            ->assertSessionHas('error', 'El paciente no está bloqueado.');

        // Un nuevo ciclo vuelve a bloquear a las 3 faltas, con otro registro.
        foreach ([['08:00', '08:30'], ['08:30', '09:00'], ['09:00', '09:30']] as [$inicio, $fin]) {
            $this->actingAs($this->recepcion)->patch(route('citas.inasistencia', $this->cita(now()->subWeeks(2)->startOfWeek()->toDateString(), Cita::PROGRAMADA, $inicio, $fin)));
        }
        $this->assertTrue($this->ficha()->bloqueado);
        $this->assertDatabaseCount('bloqueo_paciente', 2);
    }

    public function test_desbloquea_un_paciente_bloqueado_sin_registro_de_bloqueo(): void
    {
        // Como paciente08 en clinica_db: bloqueado sin fila en bloqueo_paciente.
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['inasistencia' => 3, 'bloqueado' => true]);

        $this->actingAs($this->admin->fresh())->patch(route('pacientes.desbloquear', $this->paciente->id))->assertSessionHas('status');

        $this->assertSame([0, false], [$this->ficha()->inasistencia, $this->ficha()->bloqueado]);
    }

    // --- Pantallas ------------------------------------------------------------------------

    public function test_el_detalle_de_la_cita_muestra_solo_las_acciones_posibles(): void
    {
        $futura = $this->cita($this->futuro);
        $html = $this->actingAs($this->recepcion)->get(route('citas.show', $futura))->assertOk()
            ->assertSee(route('citas.confirmar', $futura))->assertSee('Reprogramar')->assertSee('Cancelar cita')
            ->assertDontSee(route('citas.atendida', $futura))->assertDontSee(route('citas.inasistencia', $futura))
            ->getContent();
        $this->sinJavascript($html);

        $pasada = $this->cita($this->pasado);
        $this->actingAs($this->recepcion)->get(route('citas.show', $pasada))->assertOk()
            ->assertSee(route('citas.atendida', $pasada))->assertSee(route('citas.inasistencia', $pasada))
            ->assertSee('queda bloqueado')
            ->assertDontSee(route('citas.confirmar', $pasada))->assertDontSee('Reprogramar')->assertDontSee('Cancelar cita');

        $cerrada = $this->cita($this->pasado, Cita::ATENDIDA, '08:30', '09:00');
        $this->actingAs($this->recepcion)->get(route('citas.show', $cerrada))->assertOk()
            ->assertDontSee(route('citas.atendida', $cerrada))->assertDontSee(route('citas.inasistencia', $cerrada));
    }

    public function test_solo_el_administrador_ve_desbloquear(): void
    {
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['inasistencia' => 3, 'bloqueado' => true]);
        $url = route('pacientes.desbloquear', $this->paciente->id);

        $html = $this->actingAs($this->admin->fresh())->get(route('pacientes.show', $this->paciente->id))->assertOk()
            ->assertSee($url)->assertSee('bloqueado por inasistencias (3)')->getContent();
        $this->sinJavascript($html);

        $this->actingAs($this->recepcion)->get(route('pacientes.show', $this->paciente->id))->assertOk()
            ->assertDontSee($url)->assertSee('Solo el administrador puede desbloquearlo');

        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['bloqueado' => false]);
        $this->actingAs($this->admin->fresh())->get(route('pacientes.show', $this->paciente->id))->assertDontSee($url);
    }
}
