<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\AsistenciaCita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Corrección de una inasistencia registrada por error: solo el administrador; la cita pasa a
 * «Atendida», se descuenta del contador y, si corresponde, el paciente se desbloquea.
 */
class CorreccionInasistenciaTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $recepcion;

    private User $medico;

    private User $paciente;

    private Especialidad $cardiologia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        Mail::fake();

        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $this->recepcion->id]);
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
    }

    private function inasistencias(): int
    {
        return (int) DB::table('paciente')->where('id_paciente', $this->paciente->id)->value('inasistencia');
    }

    private function faltaRegistrada(int $diasAtras, string $hora = '08:00'): Cita
    {
        $cita = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, now()->subDays($diasAtras)->toDateString(), $hora, '08:30'));
        app(AsistenciaCita::class)->registrarInasistencia($cita, $this->recepcion->id);

        return $cita->fresh();
    }

    public function test_el_administrador_corrige_la_inasistencia_y_se_descuenta(): void
    {
        $cita = $this->faltaRegistrada(1);
        $this->assertSame(1, $this->inasistencias());

        $this->actingAs($this->admin)->get(route('citas.show', $cita))->assertOk()->assertSee('Corregir inasistencia');

        $this->actingAs($this->admin)->patch(route('citas.corregir_inasistencia', $cita), ['motivo_correccion' => ''])
            ->assertSessionHasErrorsIn('correccion', ['motivo_correccion' => 'Indica por qué se corrige la inasistencia.']);

        $this->actingAs($this->admin)->patch(route('citas.corregir_inasistencia', $cita), ['motivo_correccion' => 'El paciente sí vino; se marcó por error.'])
            ->assertRedirect(route('citas.show', $cita))
            ->assertSessionHas('status', "Inasistencia corregida: la cita {$cita->codigo} quedó como «Atendida». El paciente tiene 0 inasistencias.");

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertSame(0, $this->inasistencias());
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_anterior' => 'No asistió', 'estado_nuevo' => 'Atendida',
            'motivo' => 'Corrección de inasistencia: El paciente sí vino; se marcó por error.', 'registrado_por' => $this->admin->id]);
        $this->assertDatabaseHas('notificacion', ['id_usuario' => $this->paciente->id, 'tipo' => Notificacion::INASISTENCIA_CORREGIDA, 'canal' => 'Sistema']);

        // Ya corregida, no se vuelve a corregir ni se descuenta otra vez.
        $this->actingAs($this->admin)->patch(route('citas.corregir_inasistencia', $cita), ['motivo_correccion' => 'Otra vez'])->assertSessionHas('error');
        $this->assertSame(0, $this->inasistencias());
    }

    public function test_si_estaba_bloqueado_por_ese_error_se_desbloquea(): void
    {
        $this->faltaRegistrada(3);
        $this->faltaRegistrada(2);
        $tercera = $this->faltaRegistrada(1);
        $this->assertTrue((bool) DB::table('paciente')->where('id_paciente', $this->paciente->id)->value('bloqueado'));

        $this->actingAs($this->admin)->patch(route('citas.corregir_inasistencia', $tercera), ['motivo_correccion' => 'Error de registro'])
            ->assertSessionHas('status', "Inasistencia corregida: la cita {$tercera->codigo} quedó como «Atendida». El paciente tiene 2 inasistencias y quedó desbloqueado.");

        $this->assertSame(2, $this->inasistencias());
        $this->assertFalse((bool) DB::table('paciente')->where('id_paciente', $this->paciente->id)->value('bloqueado'));
        $this->assertNotNull(DB::table('bloqueo_paciente')->where('id_paciente', $this->paciente->id)->value('fecha_desbloqueo'));
        $this->assertDatabaseHas('bitacora', ['accion' => 'Desbloqueo', 'id_registro' => $this->paciente->id]);
    }

    public function test_solo_el_administrador_corrige_y_solo_inasistencias(): void
    {
        $cita = $this->faltaRegistrada(1);

        $this->actingAs($this->recepcion)->get(route('citas.show', $cita))->assertOk()->assertDontSee('Corregir inasistencia');
        foreach ([$this->recepcion, $this->medico, $this->paciente] as $otro) {
            $this->actingAs($otro)->patch(route('citas.corregir_inasistencia', $cita), ['motivo_correccion' => 'x'])->assertForbidden();
        }
        $this->assertSame(Cita::NO_ASISTIO, $cita->fresh()->estado);
        $this->assertSame(1, $this->inasistencias());

        $programada = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->proximoLunes()->toDateString(), '08:00', '08:30');
        $this->actingAs($this->admin)->patch(route('citas.corregir_inasistencia', $programada), ['motivo_correccion' => 'x'])
            ->assertSessionHas('error', 'Solo se corrige una cita registrada como «No asistió».');
        $this->actingAs($this->admin)->get(route('citas.show', $programada))->assertDontSee('Corregir inasistencia');
    }
}
