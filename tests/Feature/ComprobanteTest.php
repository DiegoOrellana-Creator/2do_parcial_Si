<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Pago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Comprobante de pago, estado del pago en las listas e ingresos por método en Reportes.
 */
class ComprobanteTest extends TestCase
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

    private function citaCobrada(string $hora = '08:00', string $metodo = 'Efectivo'): Cita
    {
        $this->actingAs($this->recepcion)->post(route('citas.store'), [
            'id_paciente' => $this->paciente->id, 'id_especialidad' => $this->cardiologia->id_especialidad,
            'fecha' => $this->lunes, 'franja' => $this->medico->id . '|' . $hora,
        ])->assertSessionHasNoErrors();

        return $this->cobrarReserva($this->recepcion, $metodo);
    }

    public function test_el_paciente_y_la_recepcion_ven_el_comprobante_imprimible(): void
    {
        $cita = $this->citaCobrada();
        $pago = Pago::sole();

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $cita))->assertSee(route('mis-citas.comprobante', $cita), false);
        $html = $this->actingAs($this->paciente)->get(route('mis-citas.comprobante', $cita))->assertOk()
            ->assertSee('Comprobante de pago')->assertSee('N.º ' . str_pad((string) $pago->id_pago, 6, '0', STR_PAD_LEFT))
            ->assertSee('Paciente01 Prueba')->assertSee($cita->codigo)->assertSee('Bs 200,00')->assertSee('Efectivo')
            ->assertSee('Ctrl+P')->assertSee(route('mis-citas.show', $cita), false)->getContent();
        $this->sinJavascript($html);

        $this->actingAs($this->recepcion)->get(route('citas.show', $cita))->assertSee(route('citas.comprobante', $cita), false);
        $this->actingAs($this->recepcion)->get(route('citas.comprobante', $cita))->assertOk()->assertSee(route('citas.show', $cita), false);
        $this->actingAs($this->admin)->get(route('citas.comprobante', $cita))->assertOk();
    }

    public function test_sin_pago_o_de_otro_paciente_no_hay_comprobante(): void
    {
        $sinPagar = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '10:00', '10:30');
        $this->actingAs($this->paciente)->get(route('mis-citas.comprobante', $sinPagar))->assertNotFound();
        $this->actingAs($this->recepcion)->get(route('citas.comprobante', $sinPagar))->assertNotFound();
        $this->actingAs($this->recepcion)->get(route('citas.show', $sinPagar))->assertDontSee('Ver comprobante');

        $cita = $this->citaCobrada();
        $this->actingAs($this->pacienteDe('paciente02'))->get(route('mis-citas.comprobante', $cita))->assertNotFound();
        $this->actingAs($this->medico)->get(route('citas.comprobante', $cita))->assertForbidden();
        $this->actingAs($this->paciente)->get(route('citas.comprobante', $cita))->assertForbidden();

        // Una reserva sin pagar no tiene comprobante (todavía no es una cita).
        $this->actingAs($this->recepcion)->post(route('citas.store'), [
            'id_paciente' => $this->paciente->id, 'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|09:00',
        ]);
        $this->actingAs($this->recepcion)->get(route('citas.comprobante', $this->ultimaReserva()->id_cita))->assertNotFound();
    }

    public function test_las_listas_muestran_si_la_cita_esta_pagada(): void
    {
        $pagada = $this->citaCobrada();
        $sinPagar = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '10:00', '10:30'));

        foreach ([[$this->recepcion, route('citas.index')], [$this->paciente, route('mis-citas.index')]] as [$usuario, $url]) {
            $html = $this->actingAs($usuario)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#' . preg_quote($pagada->codigo, '#') . '.*?badge--confirmada">\s*Pagada#s', $html);
            $this->assertMatchesRegularExpression('#' . preg_quote($sinPagar->codigo, '#') . '.*?badge--no-asistio">\s*Sin pagar#s', $html);
        }
    }

    public function test_reportes_muestra_los_ingresos_por_metodo(): void
    {
        $this->citaCobrada('08:00', 'Efectivo');
        $this->citaCobrada('09:00', 'QR bancario');
        $this->citaCobrada('09:30', 'Efectivo');

        $r = $this->actingAs($this->admin)->get(route('reportes.index', ['desde' => now()->toDateString(), 'hasta' => now()->toDateString()]))->assertOk()
            ->assertSee('Ingresos por método de pago');
        $this->assertSame([['Efectivo', 2, 400.0], ['QR bancario', 1, 200.0]],
            $r->viewData('porMetodo')->map(fn ($f) => [$f->metodo, (int) $f->cantidad, (float) $f->total])->all());
        $r->assertSee('Bs 400,00')->assertSee('67 %');

        $this->actingAs($this->admin)->get(route('reportes.index', ['desde' => '2020-01-01', 'hasta' => '2020-01-31']))
            ->assertSee('No hay pagos registrados en el periodo seleccionado.');
    }
}
