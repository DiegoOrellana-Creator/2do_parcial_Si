<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * El paciente cancela y reprograma sus propias citas.
 * El médico de prueba atiende los lunes de 08:00 a 10:00, cada 30 minutos.
 */
class PacienteGestionaCitaTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    private User $otroPaciente;

    private string $lunes;

    private Cita $cita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->otroPaciente = $this->pacienteDe('ajeno');
        $this->lunes = $this->proximoLunes()->toDateString();
        $this->cita = $this->citaDe($this->paciente);
    }

    private function citaDe(User $paciente, string $inicio = '08:00', string $fin = '08:30', string $estado = Cita::PROGRAMADA, ?string $fecha = null): Cita
    {
        return Cita::findOrFail($this->insertarCita($paciente, $this->medico, $this->cardiologia, $fecha ?? $this->lunes, $inicio, $fin, $estado));
    }

    private function reprogramar(array $cambios = [], ?Cita $cita = null)
    {
        return $this->actingAs($this->paciente)->patch(route('mis-citas.reprogramar.guardar', $cita ?? $this->cita), array_merge([
            'fecha' => $this->lunes,
            'franja' => $this->medico->id . '|09:00',
            'motivo_reprogramacion' => 'Tengo un viaje.',
        ], $cambios));
    }

    // --- Acceso ------------------------------------------------------------------------

    public function test_nadie_gestiona_citas_de_otro_paciente(): void
    {
        $ajena = $this->citaDe($this->otroPaciente, '09:30', '10:00');

        $this->actingAs($this->paciente)->get(route('mis-citas.reprogramar', $ajena))->assertNotFound();
        $this->reprogramar([], $ajena)->assertNotFound();
        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $ajena), ['motivo_cancelacion' => 'x'])->assertNotFound();

        $fresca = $ajena->fresh();
        $this->assertSame([Cita::PROGRAMADA, '09:30:00'], [$fresca->estado, $fresca->hora_inicio]);
    }

    public function test_solo_el_perfil_paciente_usa_estas_rutas(): void
    {
        $this->patch(route('mis-citas.cancelar', $this->cita), ['motivo_cancelacion' => 'x'])->assertRedirect(route('login'));
        $this->get(route('mis-citas.reprogramar', $this->cita))->assertRedirect(route('login'));

        foreach ([$this->cuenta('recepcion', 'Recepcionista'), $this->medico, $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->patch(route('mis-citas.cancelar', $this->cita), ['motivo_cancelacion' => 'x'])->assertForbidden();
            $this->actingAs($otro)->get(route('mis-citas.reprogramar', $this->cita))->assertForbidden();
        }
        $this->assertSame(Cita::PROGRAMADA, $this->cita->fresh()->estado);
    }

    // --- Cancelar -----------------------------------------------------------------------

    public function test_cancela_su_cita_y_libera_el_horario(): void
    {
        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $this->cita), ['motivo_cancelacion' => ''])
            ->assertSessionHasErrorsIn('cancelacion', ['motivo_cancelacion' => 'Indica el motivo de la cancelación.']);
        $this->assertSame(Cita::PROGRAMADA, $this->cita->fresh()->estado);

        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $this->cita), ['motivo_cancelacion' => 'No podré asistir.'])
            ->assertRedirect(route('mis-citas.show', $this->cita))
            ->assertSessionHas('status', "Cita {$this->cita->codigo} cancelada. La franja vuelve a estar disponible.");

        $cita = $this->cita->fresh();
        $this->assertSame([Cita::CANCELADA, 'No podré asistir.', $this->paciente->id], [$cita->estado, $cita->motivo_cancelacion, (int) $cita->cancelado_por]);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_nuevo' => 'Cancelada', 'registrado_por' => $this->paciente->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'Cambio de estado', 'modulo' => 'Citas', 'id_registro' => $cita->id_cita, 'id_usuario' => $this->paciente->id]);
        $this->assertSame(1, Cita::count());

        // El horario queda libre y no se cancela dos veces.
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), [
            'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:00',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $this->cita), ['motivo_cancelacion' => 'Otra vez'])->assertSessionHas('error');
    }

    public function test_no_cancela_ni_reprograma_citas_pasadas_o_cerradas(): void
    {
        $pasado = now()->subWeek()->startOfWeek()->toDateString();
        $citas = [
            $this->citaDe($this->paciente, '08:00', '08:30', Cita::PROGRAMADA, $pasado),
            $this->citaDe($this->paciente, '08:30', '09:00', Cita::ATENDIDA, $pasado),
            $this->citaDe($this->paciente, '09:30', '10:00', Cita::CANCELADA),
        ];

        foreach ($citas as $cita) {
            $this->actingAs($this->paciente)->get(route('mis-citas.show', $cita))->assertOk()->assertDontSee('Reprogramar')->assertDontSee('Cancelar cita');
            $this->actingAs($this->paciente)->get(route('mis-citas.reprogramar', $cita))->assertRedirect(route('mis-citas.show', $cita))->assertSessionHas('error');
            $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $cita), ['motivo_cancelacion' => 'x'])->assertSessionHas('error');
            $this->reprogramar([], $cita)->assertSessionHasErrors('franja');
            $this->assertSame($cita->estado, $cita->fresh()->estado);
        }
    }

    // --- Reprogramar ------------------------------------------------------------------

    public function test_reprograma_su_cita_y_vuelve_a_programada(): void
    {
        DB::table('citas')->where('id_cita', $this->cita->id_cita)->update(['estado' => Cita::CONFIRMADA]);

        $this->reprogramar()
            ->assertRedirect(route('mis-citas.show', $this->cita))
            ->assertSessionHas('status', "Tu cita {$this->cita->codigo} se reprogramó y quedó en estado «Programada».");

        $cita = $this->cita->fresh();
        $this->assertSame(['09:00:00', '09:30:00', Cita::PROGRAMADA, (int) $this->paciente->id], [$cita->hora_inicio, $cita->hora_fin, $cita->estado, (int) $cita->id_paciente]);
        $this->assertSame(1, Cita::count());
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_anterior' => 'Confirmada', 'estado_nuevo' => 'Programada', 'registrado_por' => $this->paciente->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'Reprogramación', 'id_registro' => $cita->id_cita, 'id_usuario' => $this->paciente->id]);
    }

    public function test_no_puede_cambiar_paciente_ni_especialidad(): void
    {
        $this->reprogramar(['id_paciente' => $this->otroPaciente->id, 'id_especialidad' => $this->especialidadActiva('Pediatría')->id_especialidad])->assertSessionHasNoErrors();

        $cita = $this->cita->fresh();
        $this->assertSame([(int) $this->paciente->id, (int) $this->cardiologia->id_especialidad], [(int) $cita->id_paciente, (int) $cita->id_especialidad]);
    }

    public function test_rechaza_horarios_no_disponibles_y_duplicados(): void
    {
        $this->citaDe($this->otroPaciente, '08:30', '09:00');
        DB::table('bloqueo_agenda')->insert(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:30:00', 'hora_final' => '10:00:00']);
        $casos = [
            '08:00' => 'Esa franja ya está ocupada por otra cita.',
            '08:30' => 'Esa franja ya está ocupada por otra cita.',
            '09:30' => 'El médico tiene la agenda bloqueada en esa franja.',
            '09:15' => 'La hora elegida no corresponde a ningún horario de atención del médico.',
        ];
        foreach ($casos as $hora => $mensaje) {
            $this->reprogramar(['franja' => $this->medico->id . '|' . $hora])->assertSessionHasErrors(['franja' => $mensaje]);
        }
        $this->reprogramar(['motivo_reprogramacion' => ''])->assertSessionHasErrors(['motivo_reprogramacion' => 'Indica el motivo de la reprogramación.']);

        // Otra cita pendiente con el mismo médico ese día: duplicada.
        $otraFecha = $this->proximoLunes()->addWeek()->toDateString();
        $segunda = $this->citaDe($this->paciente, '08:00', '08:30', Cita::PROGRAMADA, $otraFecha);
        $this->reprogramar([], $segunda)->assertSessionHasErrors(['franja' => 'Ya tienes una cita pendiente con ese médico ese día.']);
        $this->assertSame($otraFecha, substr($segunda->fresh()->fecha_cita, 0, 10));

        $this->assertSame('08:00:00', $this->cita->fresh()->hora_inicio);
    }

    public function test_el_paciente_bloqueado_cancela_pero_no_reprograma(): void
    {
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['bloqueado' => true]);

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $this->cita))->assertOk()
            ->assertSee('Cancelar cita')->assertDontSee(route('mis-citas.reprogramar', $this->cita));
        $this->actingAs($this->paciente)->get(route('mis-citas.reprogramar', $this->cita))
            ->assertRedirect(route('mis-citas.show', $this->cita))->assertSessionHas('error');
        $this->reprogramar()->assertSessionHasErrors(['id_paciente' => 'El paciente está bloqueado y no puede registrar citas.']);
        $this->assertSame('08:00:00', $this->cita->fresh()->hora_inicio);

        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $this->cita), ['motivo_cancelacion' => 'No iré.'])->assertSessionHas('status');
        $this->assertSame(Cita::CANCELADA, $this->cita->fresh()->estado);
    }

    // --- Pantallas --------------------------------------------------------------------

    public function test_pantallas(): void
    {
        $this->especialidadActiva('Pediatría');
        $this->citaDe($this->otroPaciente, '09:30', '10:00');

        $html = $this->actingAs($this->paciente)->get(route('mis-citas.show', $this->cita))->assertOk()
            ->assertSee(route('mis-citas.reprogramar', $this->cita))->assertSee(route('mis-citas.cancelar', $this->cita))
            ->assertDontSee('comunícate con la clínica')
            ->getContent();
        $this->sinJavascript($html);

        $html = $this->actingAs($this->paciente)->get(route('mis-citas.reprogramar', $this->cita))->assertOk()
            ->assertSee('Reprogramar cita')->assertSee('08:30–09:00')->assertSee('09:00–09:30')
            ->assertDontSee('08:00–08:30')->assertDontSee('09:30–10:00')->assertDontSee('Ajeno')->assertDontSee('Pediatría')
            ->assertDontSee('name="paciente"', false)
            ->getContent();
        $this->assertStringContainsString('value="' . $this->lunes . '"', $html);
        $this->sinJavascript($html);

        // Las consultas del paciente no llevan sus datos en la URL.
        foreach ([route('mis-citas.disponibilidad'), route('mis-citas.create')] as $url) {
            $this->actingAs($this->paciente)->get($url)->assertOk()->assertDontSee('name="paciente"', false);
        }
    }
}
