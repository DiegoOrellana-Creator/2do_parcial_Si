<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HistorialEstadoCita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Reprogramación de citas. El médico de prueba atiende los lunes de 08:00 a 10:00, cada 30 minutos.
 */
class ReprogramarCitaTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    private string $lunes;

    private Cita $cita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->lunes = $this->proximoLunes()->toDateString();
        $this->cita = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '08:00', '08:30'));
    }

    private function reprogramar(User $como, array $cambios = [], ?Cita $cita = null)
    {
        return $this->actingAs($como)->patch(route('citas.reprogramar.guardar', $cita ?? $this->cita), array_merge([
            'fecha' => $this->lunes,
            'franja' => $this->medico->id . '|09:00',
            'motivo_reprogramacion' => 'El paciente pidió otra hora.',
        ], $cambios));
    }

    private function sinCambios(): void
    {
        $cita = $this->cita->fresh();
        $this->assertSame([(int) $this->medico->id, '08:00:00', '08:30:00', 'Programada'], [(int) $cita->id_medico, $cita->hora_inicio, $cita->hora_fin, $cita->estado]);
    }

    // --- Acceso --------------------------------------------------------------------

    public function test_solo_quien_puede_registrar_y_cancelar_reprograma(): void
    {
        $this->get(route('citas.reprogramar', $this->cita))->assertRedirect(route('login'));
        $this->patch(route('citas.reprogramar.guardar', $this->cita), [])->assertRedirect(route('login'));

        $soloRegistra = $this->cuenta('solo_registra');
        $idRol = DB::table('rol')->insertGetId(['nombre' => 'Solo registra'], 'id_rol');
        DB::table('rol_permiso')->insert(['id_rol' => $idRol, 'id_permiso' => DB::table('permiso')->where('codigo', 'citas.crear')->value('id_permiso')]);
        DB::table('usuario_rol')->insert(['id_usuario' => $soloRegistra->id, 'id_rol' => $idRol]);

        foreach ([$this->paciente, $this->medico, $this->cuenta('nadie'), $soloRegistra->fresh()] as $otro) {
            $this->actingAs($otro)->get(route('citas.reprogramar', $this->cita))->assertForbidden();
            $this->reprogramar($otro)->assertForbidden();
        }
        $this->sinCambios();

        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $this->actingAs($recepcion)->get(route('citas.reprogramar', $this->cita))->assertOk();
        $this->reprogramar($recepcion)->assertSessionHasNoErrors();
        $this->assertSame('09:00:00', $this->cita->fresh()->hora_inicio);
    }

    // --- Reprogramación correcta -------------------------------------------------------

    public function test_mueve_la_cita_conserva_su_identidad_y_libera_la_franja(): void
    {
        $this->reprogramar($this->admin)
            ->assertRedirect(route('citas.show', $this->cita))
            ->assertSessionHas('status', "Cita {$this->cita->codigo} reprogramada correctamente.");

        $cita = $this->cita->fresh();
        $this->assertSame(['09:00:00', '09:30:00', $this->cita->codigo, (int) $this->paciente->id], [$cita->hora_inicio, $cita->hora_fin, $cita->codigo, (int) $cita->id_paciente]);
        $this->assertSame(1, Cita::count());

        $historial = HistorialEstadoCita::where('id_cita', $cita->id_cita)->sole();
        $this->assertSame(['Programada', 'Programada', $this->admin->id], [$historial->estado_anterior, $historial->estado_nuevo, (int) $historial->registrado_por]);
        $fecha = date('d/m/Y', strtotime($this->lunes));
        $cambio = "De {$fecha} 08:00–08:30 con Medico01 Prueba a {$fecha} 09:00–09:30 con Medico01 Prueba. Motivo: El paciente pidió otra hora.";
        $this->assertSame("Cita reprogramada. {$cambio}", $historial->motivo);

        $evento = Bitacora::where('accion', 'Reprogramación')->sole();
        $this->assertSame(['Citas', (int) $cita->id_cita, $this->admin->id, "Cita {$cita->codigo}. {$cambio}"], [$evento->modulo, (int) $evento->id_registro, (int) $evento->id_usuario, $evento->detalle]);

        // La hora anterior vuelve a estar libre.
        $otro = $this->pacienteDe('paciente02');
        $this->actingAs($this->admin)->post(route('citas.store'), [
            'id_paciente' => $otro->id, 'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:00',
        ])->assertSessionHasNoErrors();
    }

    public function test_una_cita_confirmada_vuelve_a_programada(): void
    {
        $this->cita->update(['estado' => Cita::CONFIRMADA]);

        $this->reprogramar($this->admin)->assertSessionHasNoErrors();

        $this->assertSame(Cita::PROGRAMADA, $this->cita->fresh()->estado);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $this->cita->id_cita, 'estado_anterior' => 'Confirmada', 'estado_nuevo' => 'Programada']);
    }

    public function test_puede_pasar_a_otro_medico_de_la_especialidad_a_la_misma_hora(): void
    {
        $otroMedico = $this->medicoCon('medico02', $this->cardiologia);

        $this->reprogramar($this->admin, ['franja' => $otroMedico->id . '|08:00'])->assertSessionHasNoErrors();

        $cita = $this->cita->fresh();
        $this->assertSame([(int) $otroMedico->id, '08:00:00'], [(int) $cita->id_medico, $cita->hora_inicio]);
    }

    public function test_no_se_puede_cambiar_el_paciente_ni_la_especialidad(): void
    {
        $otro = $this->pacienteDe('paciente02');
        $pediatria = $this->especialidadActiva('Pediatría');

        $this->reprogramar($this->admin, ['id_paciente' => $otro->id, 'id_especialidad' => $pediatria->id_especialidad])->assertSessionHasNoErrors();

        $cita = $this->cita->fresh();
        $this->assertSame([(int) $this->paciente->id, (int) $this->cardiologia->id_especialidad], [(int) $cita->id_paciente, (int) $cita->id_especialidad]);
    }

    // --- Rechazos ------------------------------------------------------------------------

    public function test_rechaza_franjas_no_disponibles(): void
    {
        $otro = $this->pacienteDe('paciente02');
        $this->insertarCita($otro, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00');
        DB::table('bloqueo_agenda')->insert(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:30:00', 'hora_final' => '10:00:00']);
        $otraEspecialidad = $this->medicoCon('pediatra', $this->especialidadActiva('Pediatría'));
        $inactivo = $this->medicoCon('inactivo', $this->cardiologia, [1, '08:00', '10:00', 30], User::ESTADO_INACTIVO);

        $casos = [
            '08:00' => 'Esa franja ya está ocupada por otra cita.',       // la suya
            '08:30' => 'Esa franja ya está ocupada por otra cita.',
            '09:30' => 'El médico tiene la agenda bloqueada en esa franja.',
            '09:15' => 'La hora elegida no corresponde a ningún horario de atención del médico.',
        ];
        foreach ($casos as $hora => $mensaje) {
            $this->reprogramar($this->admin, ['franja' => $this->medico->id . '|' . $hora])->assertSessionHasErrors(['franja' => $mensaje]);
        }

        $this->reprogramar($this->admin, ['franja' => $otraEspecialidad->id . '|09:00'])->assertSessionHasErrors(['franja' => 'El médico seleccionado no ejerce esa especialidad.']);
        $this->reprogramar($this->admin, ['franja' => $inactivo->id . '|09:00'])->assertSessionHasErrors(['franja' => 'El médico seleccionado no existe o no está activo.']);
        $this->reprogramar($this->admin, ['fecha' => now()->subDay()->toDateString()])->assertSessionHasErrors(['fecha' => 'La fecha no puede ser anterior a hoy.']);
        $this->reprogramar($this->admin, ['motivo_reprogramacion' => ''])->assertSessionHasErrors(['motivo_reprogramacion' => 'Indica el motivo de la reprogramación.']);
        $this->reprogramar($this->admin, ['franja' => 'x'])->assertSessionHasErrors(['franja' => 'La franja seleccionada no es válida.']);

        $this->sinCambios();
        $this->assertSame(0, Bitacora::where('accion', 'Reprogramación')->count());
        $this->assertSame(0, HistorialEstadoCita::count());
    }

    public function test_rechaza_si_el_paciente_tiene_otra_cita_o_esta_bloqueado(): void
    {
        $otroMedico = $this->medicoCon('medico02', $this->cardiologia);
        $this->insertarCita($this->paciente, $otroMedico, $this->cardiologia, $this->lunes, '09:00', '09:30');

        $this->reprogramar($this->admin)->assertSessionHasErrors(['franja' => 'El paciente ya tiene otra cita a esa hora.']);
        $this->sinCambios();

        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['bloqueado' => true]);
        $this->reprogramar($this->admin, ['franja' => $this->medico->id . '|08:30'])
            ->assertSessionHasErrors(['id_paciente' => 'El paciente está bloqueado y no puede registrar citas.']);
        $this->sinCambios();
    }

    public function test_no_reprograma_citas_canceladas_atendidas_ni_pasadas(): void
    {
        $pasado = now()->subWeek()->startOfWeek()->toDateString();
        $citas = [
            Cita::findOrFail($this->insertarCita($this->pacienteDe('p1'), $this->medico, $this->cardiologia, $this->lunes, '09:30', '10:00', Cita::CANCELADA)),
            Cita::findOrFail($this->insertarCita($this->pacienteDe('p2'), $this->medico, $this->cardiologia, $pasado, '08:00', '08:30', Cita::ATENDIDA)),
            Cita::findOrFail($this->insertarCita($this->pacienteDe('p3'), $this->medico, $this->cardiologia, $pasado, '08:30', '09:00')),
        ];

        foreach ($citas as $cita) {
            $this->actingAs($this->admin)->get(route('citas.reprogramar', $cita))
                ->assertRedirect(route('citas.show', $cita))->assertSessionHas('error');
            $this->actingAs($this->admin)->get(route('citas.show', $cita))->assertDontSee('Reprogramar');
            $this->reprogramar($this->admin, [], $cita)->assertSessionHasErrors('franja');

            $this->assertSame($cita->hora_inicio, $cita->fresh()->hora_inicio);
        }
    }

    public function test_si_falla_el_historial_la_cita_no_cambia_ni_queda_registro(): void
    {
        HistorialEstadoCita::creating(fn () => throw new \RuntimeException('Fallo simulado.'));

        $this->withoutExceptionHandling();
        try {
            $this->reprogramar($this->admin);
            $this->fail('Se esperaba el fallo simulado.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo simulado.', $e->getMessage());
        }
        HistorialEstadoCita::flushEventListeners();

        $this->sinCambios();
        $this->assertSame(0, Bitacora::count());
    }

    // --- Pantallas ------------------------------------------------------------------------

    public function test_pantalla_de_reprogramacion(): void
    {
        $this->especialidadActiva('Pediatría');

        $this->actingAs($this->admin)->get(route('citas.show', $this->cita))
            ->assertSee(route('citas.reprogramar', $this->cita));

        // Propone la fecha actual de la cita y no ofrece su propia franja.
        $html = $this->actingAs($this->admin)->get(route('citas.reprogramar', $this->cita))
            ->assertOk()->assertSee('Reprogramar cita')->assertSee('Paciente01 Prueba')->assertSee('09:00–09:30')
            ->assertDontSee('08:00–08:30')->assertDontSee('Pediatría')->assertDontSee('name="id_paciente"', false)
            ->getContent();
        $this->assertStringContainsString('value="' . $this->lunes . '"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->sinJavascript($html);

        // Otra especialidad en la URL se ignora.
        $this->actingAs($this->admin)->get(route('citas.reprogramar', [$this->cita, 'especialidad' => 999]))
            ->assertOk()->assertSee('Cardiología')->assertDontSee('La especialidad seleccionada no existe');
    }
}
