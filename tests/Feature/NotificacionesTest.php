<?php

namespace Tests\Feature;

use App\Mail\AvisoClinica;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Notificacion;
use App\Models\Paciente;
use App\Models\User;
use App\Services\AsistenciaCita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Notificaciones: aviso en la campanita y correo al paciente. Nunca se envía un correo real
 * (Mail::fake()).
 */
class NotificacionesTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $recepcion;

    private User $medico;

    private User $paciente;

    private Especialidad $cardiologia;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        Mail::fake();

        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $this->recepcion->id]);
        $this->cardiologia = Especialidad::create(['nombre' => 'Cardiología', 'estado' => 'Activo', 'precio' => 200]);
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->lunes = $this->proximoLunes()->toDateString();
    }

    private function citaCobrada(string $hora = '08:00'): Cita
    {
        $this->actingAs($this->recepcion)->post(route('citas.store'), [
            'id_paciente' => $this->paciente->id, 'id_especialidad' => $this->cardiologia->id_especialidad,
            'fecha' => $this->lunes, 'franja' => $this->medico->id . '|' . $hora,
        ])->assertSessionHasNoErrors();

        return $this->cobrarReserva($this->recepcion);
    }

    private function avisos(User $usuario, string $canal = Notificacion::SISTEMA)
    {
        return Notificacion::where('id_usuario', $usuario->id)->where('canal', $canal)->orderBy('id_notificacion')->get();
    }

    public function test_al_generarse_la_cita_el_paciente_recibe_el_aviso_y_el_correo_con_el_pago(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.store'), [
            'id_paciente' => $this->paciente->id, 'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:00',
        ]);
        // Una reserva todavía no avisa: no es una cita.
        $this->assertSame(0, Notificacion::count());
        Mail::assertNothingSent();

        $cita = $this->cobrarReserva($this->recepcion);

        $aviso = $this->avisos($this->paciente)->sole();
        $this->assertSame([Notificacion::CITA_GENERADA, "Tu cita {$cita->codigo} quedó registrada y confirmada.", false], [$aviso->tipo, $aviso->mensaje, $aviso->leido]);
        $this->assertSame(Notificacion::ENVIADO, $this->avisos($this->paciente, Notificacion::CORREO)->sole()->estado_envio);

        Mail::assertSent(AvisoClinica::class, function (AvisoClinica $correo) use ($cita) {
            $html = $correo->render();

            return $correo->hasTo('paciente01@ejemplo.test')
                && $correo->envelope()->subject === 'Tu cita quedó confirmada · Clínica Santa Cruz'
                && str_contains($html, $cita->codigo) && str_contains($html, 'Bs 200,00') && str_contains($html, 'Efectivo')
                && str_contains($html, 'Medico01 Prueba') && ! str_contains($html, '<script');
        });
        Mail::assertSentCount(1);

        // El médico ve la nueva cita en su campanita, sin correo.
        $this->assertStringContainsString("Nueva cita {$cita->codigo}", $this->avisos($this->medico)->sole()->mensaje);
        $this->assertCount(0, $this->avisos($this->medico, Notificacion::CORREO));
    }

    public function test_avisa_la_cancelacion_y_la_reprogramacion(): void
    {
        $cita = $this->citaCobrada();

        $this->actingAs($this->recepcion)->patch(route('citas.reprogramar.guardar', $cita), [
            'fecha' => $this->lunes, 'franja' => $this->medico->id . '|09:00', 'motivo_reprogramacion' => 'Pedido del paciente',
        ])->assertSessionHasNoErrors();
        $this->assertSame(Notificacion::CITA_REPROGRAMADA, $this->avisos($this->paciente)->last()->tipo);
        $this->assertStringContainsString('a las 09:00', $this->avisos($this->paciente)->last()->mensaje);

        $this->actingAs($this->recepcion)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'Viaje'])->assertSessionHas('status');
        $this->assertSame(Notificacion::CITA_CANCELADA, $this->avisos($this->paciente)->last()->tipo);
        Mail::assertSent(AvisoClinica::class, fn (AvisoClinica $c) => $c->asunto === 'Tu cita fue cancelada'
            && in_array('La cita estaba pagada: consulta en recepción por la devolución.', $c->lineas, true));
        Mail::assertSentCount(3);
    }

    public function test_avisa_la_inasistencia_el_bloqueo_y_el_desbloqueo(): void
    {
        DB::table('paciente')->where('id_paciente', $this->paciente->id)->update(['inasistencia' => 1]);
        $pasada = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, now()->subDay()->toDateString(), '08:00', '08:30'));
        $otra = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, now()->subDays(2)->toDateString(), '08:00', '08:30'));

        app(AsistenciaCita::class)->registrarInasistencia($pasada, $this->recepcion->id);
        $this->assertSame([Notificacion::INASISTENCIA, "No asististe a la cita {$pasada->codigo}. Llevas 2 de 3 inasistencias permitidas."],
            [$this->avisos($this->paciente)->last()->tipo, $this->avisos($this->paciente)->last()->mensaje]);

        app(AsistenciaCita::class)->registrarInasistencia($otra, $this->recepcion->id);
        $this->assertSame(Notificacion::BLOQUEO, $this->avisos($this->paciente)->last()->tipo);

        $this->actingAs($this->admin)->patch(route('pacientes.desbloquear', $this->paciente->id))->assertSessionHas('status');
        $this->assertSame(Notificacion::DESBLOQUEO, $this->avisos($this->paciente)->last()->tipo);
        Mail::assertSentCount(3);
    }

    public function test_si_falla_el_correo_la_cita_se_genera_igual_y_queda_fallido(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        $cita = $this->citaCobrada();

        $this->assertSame(Cita::CONFIRMADA, $cita->estado);
        $this->assertSame(Notificacion::FALLIDO, $this->avisos($this->paciente, Notificacion::CORREO)->sole()->estado_envio);
        $this->assertCount(1, $this->avisos($this->paciente));
    }

    public function test_el_recordatorio_avisa_las_citas_de_manana_una_sola_vez(): void
    {
        $manana = now()->addDay()->toDateString();
        $deManana = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $manana, '10:00', '10:30', Cita::CONFIRMADA));
        $otro = $this->pacienteDe('paciente02');
        $this->insertarCita($otro, $this->medico, $this->cardiologia, $manana, '11:00', '11:30', Cita::CANCELADA);
        $this->insertarCita($otro, $this->medico, $this->cardiologia, $this->lunes, '11:00', '11:30');
        DB::table('citas')->insert(['codigo' => 'RES-1', 'id_paciente' => $otro->id, 'id_medico' => $this->medico->id, 'id_especialidad' => $this->cardiologia->id_especialidad,
            'fecha_cita' => $manana, 'hora_inicio' => '12:00:00', 'hora_fin' => '12:30:00', 'estado' => Cita::PENDIENTE_PAGO, 'fecha_creacion' => now()]);

        $this->artisan('citas:recordatorios')->expectsOutput("Recordatorios enviados para el {$manana}: 1.")->assertSuccessful();
        $this->artisan('citas:recordatorios')->expectsOutput("Recordatorios enviados para el {$manana}: 0.")->assertSuccessful();

        $this->assertStringContainsString($deManana->codigo, $this->avisos($this->paciente)->sole()->mensaje);
        $this->assertCount(0, $this->avisos($otro));
        Mail::assertSentCount(1);
    }

    public function test_la_campanita_muestra_los_avisos_propios_sin_leer(): void
    {
        $this->actingAs($this->paciente)->get(route('dashboard'))->assertOk()
            ->assertSee('href="' . route('notificaciones.index') . '"', false)->assertDontSee('icon-button__contador');

        $cita = $this->citaCobrada();

        $this->actingAs($this->paciente)->get(route('dashboard'))->assertSee('aria-label="Notificaciones, 1 sin leer"', false);
        $html = $this->actingAs($this->paciente)->get(route('notificaciones.index'))->assertOk()
            ->assertSee('Tienes 1 notificación sin leer.')->assertSee($cita->codigo)->getContent();
        $this->sinJavascript($html);

        // Cada uno ve solo los suyos.
        $this->actingAs($this->recepcion)->get(route('notificaciones.index'))->assertOk()->assertDontSee($cita->codigo . ' quedó registrada');
        $this->actingAs($this->recepcion)->patch(route('notificaciones.leidas'));
        $this->assertFalse($this->avisos($this->paciente)->sole()->leido);

        $this->actingAs($this->paciente)->patch(route('notificaciones.leidas'))->assertRedirect(route('notificaciones.index'));
        $this->assertTrue($this->avisos($this->paciente)->sole()->leido);
        $this->actingAs($this->paciente)->get(route('dashboard'))->assertDontSee('icon-button__contador');

        $this->app['auth']->forgetGuards();
        $this->get(route('notificaciones.index'))->assertRedirect(route('login'));
    }
}
