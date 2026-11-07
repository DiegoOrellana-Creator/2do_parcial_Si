<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Pago;
use App\Models\User;
use App\Services\Pagos\RegistroPago;
use App\Services\RegistroCita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Cobro previo: la cita nueva es una reserva «Pendiente de pago» que aparta la franja y solo se
 * genera al pagarla (en ventanilla o en línea, también con QR).
 */
class ReservasTest extends TestCase
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
        Http::preventStrayRequests();

        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $this->recepcion->id]);
        $this->cardiologia = Especialidad::create(['nombre' => 'Cardiología', 'estado' => 'Activo', 'precio' => 200]);
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->lunes = $this->proximoLunes()->toDateString();
    }

    private function solicitar(User $como, array $cambios = [])
    {
        $datos = ['id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:00', ...$cambios];

        return $como->id === $this->paciente->id
            ? $this->actingAs($como)->post(route('mis-citas.store'), $datos)
            : $this->actingAs($como)->post(route('citas.store'), ['id_paciente' => $this->paciente->id, ...$datos]);
    }

    public function test_la_reserva_aparta_la_franja_hasta_que_vence(): void
    {
        $this->solicitar($this->paciente)->assertSessionHasNoErrors();
        $reserva = $this->ultimaReserva();
        $otro = $this->pacienteDe('paciente02');

        $this->actingAs($this->recepcion)->post(route('citas.store'), ['id_paciente' => $otro->id, 'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:00'])
            ->assertSessionHasErrors(['franja' => 'Esa franja ya está ocupada por otra cita.']);

        // Pasado el plazo, la franja se libera sola y la reserva queda anulada.
        $this->travel(Cita::minutosReserva() + 1)->minutes();
        $this->actingAs($this->recepcion)->post(route('citas.store'), ['id_paciente' => $otro->id, 'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:00'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Cita::RESERVA_ANULADA, Cita::conReservas()->find($reserva->id_cita)->estado);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $reserva->id_cita, 'estado_nuevo' => 'Reserva anulada', 'motivo' => 'El plazo para pagar venció.']);

        // La pantalla de pago de la reserva vencida lleva a elegir otro horario.
        $this->actingAs($this->paciente)->get(route('mis-citas.reserva', $reserva))->assertNotFound();
    }

    public function test_una_reserva_vencida_ya_no_se_puede_pagar(): void
    {
        $this->solicitar($this->paciente);
        $reserva = $this->ultimaReserva();

        $this->travel(Cita::minutosReserva() + 1)->minutes();
        $this->actingAs($this->paciente)->get(route('mis-citas.reserva', $reserva))
            ->assertRedirect(route('mis-citas.create'))->assertSessionHas('error');
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $reserva))->assertNotFound();
        $this->actingAs($this->recepcion)->post(route('citas.pago', $reserva), ['metodo' => 'Efectivo'])->assertNotFound();
        $this->assertSame(0, Pago::where('estado_pago', Pago::PAGADO)->count());
    }

    public function test_el_paciente_cancela_su_solicitud_sin_pagar(): void
    {
        $this->solicitar($this->paciente);
        $reserva = $this->ultimaReserva();

        $this->actingAs($this->pacienteDe('paciente02'))->get(route('mis-citas.reserva', $reserva))->assertNotFound();
        $this->actingAs($this->pacienteDe('paciente03'))->post(route('mis-citas.reserva.anular', $reserva))->assertNotFound();

        $this->actingAs($this->paciente)->post(route('mis-citas.reserva.anular', $reserva))
            ->assertRedirect(route('mis-citas.index'))->assertSessionHas('status', 'Reserva anulada: el horario vuelve a estar disponible y no se realizó ningún cobro.');

        $this->assertSame(Cita::RESERVA_ANULADA, Cita::conReservas()->find($reserva->id_cita)->estado);
        $this->solicitar($this->recepcion)->assertSessionHasNoErrors();
    }

    public function test_la_recepcion_anula_la_reserva_y_corta_un_qr_abierto(): void
    {
        $this->solicitar($this->recepcion);
        $reserva = $this->ultimaReserva();
        $this->actingAs($this->recepcion)->post(route('citas.pago.qr.generar', $reserva));
        $pago = Pago::sole();
        $enlace = app(RegistroPago::class)->sesionAbierta($pago)['url'];

        $this->actingAs($this->recepcion)->post(route('citas.reserva.anular', $reserva))->assertRedirect(route('citas.index'))->assertSessionHas('status');

        $this->assertSame(Pago::FALLIDO, $pago->fresh()->estado_pago);
        $this->app['auth']->forgetGuards();
        $this->get($enlace)->assertNotFound();
    }

    public function test_el_paciente_paga_su_reserva_con_qr_y_se_genera_la_cita(): void
    {
        $this->solicitar($this->paciente);
        $reserva = $this->ultimaReserva();

        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $reserva))->assertRedirect(route('mis-citas.pago.qr', $pago = Pago::sole()));
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago))->assertOk()->assertSee('← Pagar la consulta', false)->assertDontSee($reserva->codigo . ' quedó');
        $enlace = app(RegistroPago::class)->sesionAbierta($pago)['url'];

        $this->app['auth']->forgetGuards();
        $this->get($this->post($enlace, ['accion' => 'pagar'])->headers->get('Location'))->assertSee('Pago recibido');

        $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago))->assertRedirect(route('mis-citas.show', $reserva->id_cita));
        $cita = Cita::findOrFail($reserva->id_cita);
        $this->assertSame(Cita::CONFIRMADA, $cita->estado);
        $this->actingAs($this->paciente)->get(route('mis-citas.index'))->assertSee($cita->codigo);
    }

    public function test_un_pago_tardio_no_revive_una_reserva_anulada(): void
    {
        $this->solicitar($this->paciente);
        $reserva = $this->ultimaReserva();
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $reserva));
        $pago = Pago::sole();

        app(RegistroCita::class)->anularReserva($reserva, 'Prueba', null);
        // La pasarela confirma el cobro después de anulada (por ejemplo, un aviso tardío).
        $this->assertTrue(app(RegistroPago::class)->confirmar($pago, 200.0));

        $this->assertSame(Cita::RESERVA_ANULADA, Cita::conReservas()->find($reserva->id_cita)->estado);
        $this->assertStringContainsString('reserva ya había vencido; corresponde revisar una devolución', Bitacora::where('modulo', 'Pagos')->latest('id_bitacora')->value('detalle'));
    }

    public function test_en_ventanilla_la_tarjeta_abre_la_pasarela_y_vuelve_a_la_recepcion(): void
    {
        $this->solicitar($this->recepcion);
        $reserva = $this->ultimaReserva();
        $this->actingAs($this->recepcion)->get(route('citas.cobro', $reserva))->assertSee('Tarjeta (abre la pasarela de pago)');

        $pasarela = $this->actingAs($this->recepcion)->post(route('citas.pago', $reserva), ['metodo' => 'Tarjeta'])->headers->get('Location');
        $pago = Pago::sole();
        $this->assertStringStartsWith(route('pagos.simulado', $pago) . '?expires=', $pasarela);
        $this->assertSame(Pago::PENDIENTE, $pago->estado_pago);

        // Cancelar en la pasarela vuelve a la pantalla de cobro sin cobrar.
        $this->post($pasarela, ['accion' => 'cancelar'])->assertRedirect(route('citas.pago.cancelado', $pago));
        $this->actingAs($this->recepcion)->get(route('citas.pago.cancelado', $pago))->assertRedirect(route('citas.cobro', $reserva))->assertSessionHas('error');
        $this->assertSame(Pago::FALLIDO, $pago->fresh()->estado_pago);

        // Pagar en la pasarela genera la cita y vuelve a su detalle.
        $pasarela = $this->actingAs($this->recepcion)->post(route('citas.pago', $reserva), ['metodo' => 'Tarjeta'])->headers->get('Location');
        $segundo = Pago::where('estado_pago', Pago::PENDIENTE)->sole();
        $this->post($pasarela, ['accion' => 'pagar'])->assertRedirect(route('citas.pago.retorno', $segundo));
        $this->actingAs($this->recepcion)->get(route('citas.pago.retorno', $segundo))
            ->assertRedirect(route('citas.show', $reserva->id_cita))->assertSessionHas('status', "Pago con tarjeta recibido: la cita {$reserva->codigo} quedó registrada y pagada.");
        $this->assertSame(Cita::CONFIRMADA, Cita::findOrFail($reserva->id_cita)->estado);

        // El médico o el paciente no usan la vuelta de la recepción.
        $this->actingAs($this->medico)->get(route('citas.pago.retorno', $segundo))->assertForbidden();
        $this->actingAs($this->paciente)->get(route('citas.pago.retorno', $segundo))->assertForbidden();
    }

    public function test_sin_precio_no_se_registra_la_cita(): void
    {
        $this->cardiologia->update(['precio' => null]);

        $this->solicitar($this->recepcion)->assertSessionHasErrors(['id_especialidad' => 'La especialidad no tiene precio registrado: no se puede cobrar la cita.']);
        $this->solicitar($this->paciente)->assertSessionHasErrors('id_especialidad');
        $this->assertSame(0, Cita::conReservas()->count());
    }

    public function test_las_reservas_no_aparecen_en_listas_agenda_ni_reportes(): void
    {
        $this->solicitar($this->paciente);
        $reserva = $this->ultimaReserva();

        $this->actingAs($this->admin)->get(route('citas.index'))->assertDontSee($reserva->codigo);
        $this->actingAs($this->admin)->get(route('pacientes.show', $this->paciente->id))->assertDontSee($reserva->codigo);
        $this->actingAs($this->medico)->get(route('consultas.index'))->assertDontSee($reserva->codigo);
        $r = $this->actingAs($this->admin)->get(route('reportes.index', ['desde' => $this->lunes, 'hasta' => $this->lunes]))->assertOk();
        $this->assertSame(0, $r->viewData('total'));
        $this->assertSame(0, $this->actingAs($this->admin)->get(route('dashboard'))->viewData('estadisticas')['pendientes']);

        // Al cobrarla, aparece en todas partes.
        $cita = $this->cobrarReserva($this->recepcion);
        $this->actingAs($this->admin)->get(route('citas.index'))->assertSee($cita->codigo);
        $this->assertSame(1, $this->actingAs($this->admin)->get(route('reportes.index', ['desde' => $this->lunes, 'hasta' => $this->lunes]))->viewData('total'));
    }
}
