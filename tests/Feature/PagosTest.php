<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Pago;
use App\Models\User;
use App\Services\Pagos\PasarelaStripe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Pasarela de pago: pago en línea del paciente (simulado y Stripe), avisos de Stripe y cobro en ventanilla.
 * Nunca se conecta a Stripe: las respuestas se simulan con Http::fake().
 */
class PagosTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private const SECRETO_WEBHOOK = 'whsec_prueba';

    private User $admin;

    private User $recepcion;

    private User $medico;

    private User $paciente;

    private Especialidad $cardiologia;

    private Cita $cita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        Http::preventStrayRequests();
        config(['pagos.pasarela' => 'simulada']);

        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $this->recepcion->id]);
        $this->cardiologia = Especialidad::create(['nombre' => 'Cardiología', 'estado' => 'Activo', 'precio' => 200]);
        $this->medico = $this->medicoCon('medico01', $this->cardiologia, null);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->cita = Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->proximoLunes()->toDateString(), '08:00', '08:30'));
    }

    private function usarStripe(): void
    {
        config(['pagos.pasarela' => 'stripe', 'pagos.stripe.secret' => 'sk_test_prueba', 'pagos.stripe.webhook_secret' => self::SECRETO_WEBHOOK]);
    }

    /** Respuestas simuladas de Stripe: crear sesión y consultarla con el estado indicado. */
    private function fingirStripe(array $sesion = ['payment_status' => 'unpaid', 'status' => 'open']): void
    {
        Http::fake(function (PeticionHttp $peticion) use ($sesion) {
            $ruta = parse_url($peticion->url(), PHP_URL_PATH);

            return match (true) {
                $peticion->method() === 'POST' && $ruta === '/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
                $peticion->method() === 'GET' && $ruta === '/v1/checkout/sessions/cs_test_1' => Http::response(['id' => 'cs_test_1', 'amount_total' => 20000, ...$sesion]),
                str_ends_with((string) $ruta, '/expire') => Http::response(['id' => 'cs_test_1', 'status' => 'expired']),
                default => Http::response(['error' => ['message' => 'no esperado']], 500),
            };
        });
    }

    private function aviso(string $tipo, array $sesion, ?string $secreto = self::SECRETO_WEBHOOK)
    {
        $contenido = json_encode(['type' => $tipo, 'data' => ['object' => ['id' => 'cs_test_1', 'amount_total' => 20000, ...$sesion]]]);
        $marca = time();
        $firma = hash_hmac('sha256', "{$marca}.{$contenido}", $secreto);

        return $this->call('POST', route('pagos.stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$marca},v1={$firma}", 'CONTENT_TYPE' => 'application/json'], $contenido);
    }

    private function pagado(): ?Pago
    {
        return Pago::where('id_cita', $this->cita->id_cita)->where('estado_pago', Pago::PAGADO)->first();
    }

    // --- Pasarela simulada ---------------------------------------------------------

    public function test_el_paciente_paga_en_linea_con_la_pasarela_simulada(): void
    {
        $html = $this->actingAs($this->paciente)->get(route('mis-citas.show', $this->cita))->assertOk()
            ->assertSee('Pago de la consulta')->assertSee('Bs 200,00')->assertSee('Pagar con tarjeta')->assertSee('Pagar con QR')->assertSee('Modo de prueba')->getContent();
        $this->sinJavascript($html);

        $r = $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita));
        $pago = Pago::where('id_cita', $this->cita->id_cita)->sole();
        $pasarela = $r->headers->get('Location');
        $this->assertStringStartsWith(route('pagos.simulado', $pago) . '?expires=', $pasarela);
        $this->assertSame(Pago::PENDIENTE, $pago->estado_pago);
        $this->assertSame('200.00', $pago->monto);
        $this->assertStringStartsWith('SIM-', $pago->id_transaccion);

        $this->sinJavascript($this->get($pasarela)->assertOk()->assertSee('no cobra dinero real')->getContent());
        $this->post($pasarela, ['accion' => 'pagar'])->assertRedirect(route('mis-citas.pago.retorno', $pago));
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.retorno', $pago))
            ->assertRedirect(route('mis-citas.show', $this->cita))->assertSessionHas('status', "Pago recibido. ¡Gracias! Tu cita {$this->cita->codigo} quedó registrada y confirmada.");

        $pago->refresh();
        $this->assertSame(Pago::PAGADO, $pago->estado_pago);
        $this->assertNotNull($pago->fecha_pago);
        $this->assertSame(Cita::CONFIRMADA, $this->cita->fresh()->estado);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $this->cita->id_cita, 'estado_nuevo' => 'Confirmada', 'motivo' => 'Pago en línea recibido.']);
        $this->assertStringContainsString('Pago en línea de la cita', Bitacora::where('modulo', 'Pagos')->sole()->detalle);

        // Volver a la página de retorno o pagar de nuevo no duplica nada.
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.retorno', $pago))->assertSessionHas('status');
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->assertSessionHas('error');
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $this->cita))->assertSessionHas('error');
        $this->get($pasarela)->assertNotFound();
        $this->assertSame(1, Pago::where('id_cita', $this->cita->id_cita)->count());
        $this->actingAs($this->paciente)->get(route('mis-citas.show', $this->cita))->assertSee('Pagado')->assertDontSee('Pagar con tarjeta')->assertDontSee('Pagar con QR');
    }

    public function test_cancelar_en_la_pasarela_no_cobra(): void
    {
        $pasarela = $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->headers->get('Location');
        $pago = Pago::sole();

        $this->post($pasarela, ['accion' => 'cancelar'])->assertRedirect(route('mis-citas.pago.cancelado', $pago));
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.cancelado', $pago))
            ->assertSessionHas('error', 'Cancelaste el pago: no se realizó ningún cobro.');

        $this->assertSame(Pago::FALLIDO, $pago->fresh()->estado_pago);
        $this->assertSame(Cita::PROGRAMADA, $this->cita->fresh()->estado);
        $this->assertNull($this->pagado());
    }

    public function test_un_nuevo_intento_cierra_el_anterior(): void
    {
        $urlPrimero = $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->headers->get('Location');
        $primero = Pago::sole();
        $urlSegundo = $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->headers->get('Location');

        $this->assertSame(Pago::FALLIDO, $primero->fresh()->estado_pago);
        $segundo = Pago::where('estado_pago', Pago::PENDIENTE)->sole();

        // La sesión anterior ya no se puede pagar.
        $this->get($urlPrimero)->assertNotFound();
        $this->post($urlPrimero, ['accion' => 'pagar'])->assertNotFound();
        $this->post($urlSegundo, ['accion' => 'pagar']);
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.retorno', $segundo));
        $this->assertSame([$segundo->id_pago], Pago::where('estado_pago', Pago::PAGADO)->pluck('id_pago')->all());
    }

    public function test_solo_se_pagan_citas_propias_vigentes_y_con_precio(): void
    {
        $otro = $this->pacienteDe('paciente02');
        $this->actingAs($otro)->post(route('mis-citas.pagar', $this->cita))->assertNotFound();
        foreach ([$this->recepcion, $this->medico, $this->admin] as $personal) {
            $this->actingAs($personal)->post(route('mis-citas.pagar', $this->cita))->assertForbidden();
        }

        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita));
        $pago = Pago::sole();
        // Las páginas públicas exigen la firma: sin ella no se abren.
        $this->get(route('pagos.simulado', $pago))->assertForbidden();
        $this->post(route('pagos.simulado.completar', $pago), ['accion' => 'pagar'])->assertForbidden();
        $this->get(route('pagos.qr.listo', $pago))->assertForbidden();
        $this->get(route('pagos.qr.cancelado', $pago))->assertForbidden();
        $this->actingAs($otro)->get(route('mis-citas.pago.retorno', $pago))->assertNotFound();
        $this->actingAs($otro)->get(route('mis-citas.pago.qr', $pago))->assertNotFound();

        // Sin precio, cancelada o ya pasada: no se paga en línea.
        $sinPrecio = Especialidad::create(['nombre' => 'Pediatría', 'estado' => 'Activo']);
        DB::table('especialidad_medico')->insert(['id_medico' => $this->medico->id, 'id_especialidad' => $sinPrecio->id_especialidad]);
        $c1 = $this->insertarCita($this->paciente, $this->medico, $sinPrecio, $this->proximoLunes()->addDay()->toDateString(), '08:00', '08:30');
        $c2 = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->proximoLunes()->addDays(2)->toDateString(), '08:00', '08:30', 'Cancelada');
        $c3 = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, now()->subDays(3)->toDateString(), '08:00', '08:30');
        foreach ([$c1, $c2, $c3] as $id) {
            $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $id))->assertSessionHas('error');
            $this->assertSame(0, Pago::where('id_cita', $id)->count());
        }
        $this->actingAs($this->paciente)->get(route('mis-citas.show', $c1))->assertSee('todavía no tiene precio')->assertDontSee('Pagar con tarjeta');
        // La cita ya pasada tampoco se paga con QR desde el perfil del paciente.
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $c3))->assertSessionHas('error');
    }

    public function test_con_la_pasarela_stripe_no_existe_la_pagina_simulada(): void
    {
        $pasarela = $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->headers->get('Location');

        $this->usarStripe();
        $this->fingirStripe();
        $this->get($pasarela)->assertNotFound();
        $this->post($pasarela, ['accion' => 'pagar'])->assertNotFound();
        $this->assertNull($this->pagado());
    }

    // --- Pago con QR -------------------------------------------------------------------

    public function test_el_paciente_paga_con_qr_desde_otro_dispositivo(): void
    {
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $this->cita))
            ->assertRedirect(route('mis-citas.pago.qr', $pago = Pago::sole()));
        $this->assertSame('QR · En línea (simulado)', $pago->metodo_de_pago);

        $html = $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago))->assertOk()
            ->assertSee('src="data:image/svg+xml;base64,', false)->assertSee('Ya pagué, actualizar')->assertSee('Bs 200,00')->getContent();
        $this->sinJavascript($html);
        preg_match('#<a href="([^"]+)" class="link">Abrir la página de pago aquí</a>#', $html, $m);
        $enlace = html_entity_decode($m[1]);
        $this->assertStringStartsWith(route('pagos.simulado', $pago) . '?expires=', $enlace);

        // En el celular, sin sesión: paga y ve el resultado en una página pública.
        $this->app['auth']->forgetGuards();
        $this->get($enlace)->assertOk()->assertDontSee('paciente01@ejemplo.test')->assertDontSee('Paciente01');
        $listo = $this->post($enlace, ['accion' => 'pagar'])->headers->get('Location');
        $this->assertStringStartsWith(route('pagos.qr.listo', $pago) . '?expires=', $listo);
        $this->get($listo)->assertOk()->assertSee('Pago recibido')->assertSee($this->cita->codigo);
        $this->assertSame(Pago::PAGADO, $pago->fresh()->estado_pago);

        // En la computadora, «Ya pagué, actualizar» vuelve a la cita.
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago))
            ->assertRedirect(route('mis-citas.show', $this->cita))->assertSessionHas('status', "Pago recibido: la cita {$this->cita->codigo} quedó registrada y pagada.");
        $this->assertSame(Cita::CONFIRMADA, $this->cita->fresh()->estado);
    }

    public function test_cancelar_el_qr_desde_el_celular_no_cobra(): void
    {
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $this->cita));
        $pago = Pago::sole();
        $enlace = $this->pagos()->sesionAbierta($pago)['url'];

        $this->app['auth']->forgetGuards();
        $cancelado = $this->post($enlace, ['accion' => 'cancelar'])->headers->get('Location');
        $this->get($cancelado)->assertOk()->assertSee('Pago cancelado');

        $this->assertSame(Pago::FALLIDO, $pago->fresh()->estado_pago);
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago))
            ->assertRedirect(route('mis-citas.show', $this->cita))->assertSessionHas('error', 'El código QR ya no es válido. Genera uno nuevo si quieres pagar.');
    }

    public function test_el_qr_vence(): void
    {
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $this->cita));
        $pago = Pago::sole();

        $this->travel(31)->minutes();
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago))
            ->assertRedirect(route('mis-citas.show', $this->cita))->assertSessionHas('error');
    }

    public function test_recepcion_muestra_el_qr_y_el_pago_se_registra_solo(): void
    {
        $this->actingAs($this->recepcion)->get(route('citas.show', $this->cita))->assertSee('Mostrar QR de pago');
        $this->actingAs($this->medico)->post(route('citas.pago.qr.generar', $this->cita))->assertForbidden();
        $this->actingAs($this->paciente)->post(route('citas.pago.qr.generar', $this->cita))->assertForbidden();
        $this->assertSame(0, Pago::count());

        $this->actingAs($this->recepcion)->post(route('citas.pago.qr.generar', $this->cita))
            ->assertRedirect(route('citas.pago.qr', $pago = Pago::sole()));
        $this->actingAs($this->recepcion)->get(route('citas.pago.qr', $pago))->assertOk()
            ->assertSee('src="data:image/svg+xml;base64,', false)->assertSee('Anular QR');
        $this->actingAs($this->medico)->get(route('citas.pago.qr', $pago))->assertForbidden();

        $enlace = $this->pagos()->sesionAbierta($pago)['url'];
        $this->app['auth']->forgetGuards();
        $this->get($this->post($enlace, ['accion' => 'pagar'])->headers->get('Location'))->assertSee('Pago recibido');

        $this->actingAs($this->recepcion)->get(route('citas.pago.qr', $pago))->assertRedirect(route('citas.show', $this->cita));
        $this->assertSame(Pago::PAGADO, $pago->fresh()->estado_pago);
    }

    public function test_recepcion_anula_el_qr_y_puede_cobrar_una_cita_ya_atendida(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.pago.qr.generar', $this->cita));
        $pago = Pago::sole();
        $this->actingAs($this->recepcion)->post(route('citas.pago.qr.cancelar', $pago))
            ->assertRedirect(route('citas.show', $this->cita))->assertSessionHas('status', 'Se anuló el código QR: no se realizó ningún cobro.');
        $this->assertSame(Pago::FALLIDO, $pago->fresh()->estado_pago);

        $atendida = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, now()->subDay()->toDateString(), '08:00', '08:30', 'Atendida');
        $this->actingAs($this->recepcion)->post(route('citas.pago.qr.generar', $atendida))->assertRedirect();
        $this->assertSame(1, Pago::where('id_cita', $atendida)->where('estado_pago', Pago::PENDIENTE)->count());
    }

    public function test_con_stripe_el_qr_lleva_a_stripe_y_vuelve_a_una_pagina_publica(): void
    {
        $this->usarStripe();
        $this->fingirStripe(['payment_status' => 'paid', 'status' => 'complete']);

        $this->actingAs($this->paciente)->post(route('mis-citas.pagar.qr', $this->cita));
        $pago = Pago::sole();
        $this->assertSame('QR · Tarjeta en línea (Stripe)', $pago->metodo_de_pago);
        $this->actingAs($this->paciente)->get(route('mis-citas.pago.qr', $pago));

        Http::assertSent(fn (PeticionHttp $p) => $p->method() === 'POST'
            && str_starts_with($p['success_url'], route('pagos.qr.listo', $pago) . '?expires=')
            && str_starts_with($p['cancel_url'], route('pagos.qr.cancelado', $pago) . '?expires='));
        // Como Stripe ya informa el pago, la pantalla del QR vuelve a la cita pagada.
        $this->assertSame(Pago::PAGADO, $pago->fresh()->estado_pago);
    }

    private function pagos(): \App\Services\Pagos\RegistroPago
    {
        return app(\App\Services\Pagos\RegistroPago::class);
    }

    // --- Stripe ----------------------------------------------------------------------

    public function test_stripe_abre_la_sesion_con_el_importe_y_registra_el_pago_al_volver(): void
    {
        $this->usarStripe();
        $this->fingirStripe(['payment_status' => 'paid', 'status' => 'complete']);

        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $pago = Pago::sole();
        $this->assertSame('cs_test_1', $pago->id_transaccion);
        $this->assertSame('Tarjeta en línea (Stripe)', $pago->metodo_de_pago);

        Http::assertSent(function (PeticionHttp $p) use ($pago) {
            return $p->method() === 'POST' && $p->hasHeader('Authorization', 'Bearer sk_test_prueba')
                && $p['line_items'][0]['price_data']['currency'] === 'bob'
                && (int) $p['line_items'][0]['price_data']['unit_amount'] === 20000
                && $p['client_reference_id'] === (string) $pago->id_pago
                && $p['success_url'] === route('mis-citas.pago.retorno', $pago)
                && $p['customer_email'] === 'paciente01@ejemplo.test';
        });

        $this->actingAs($this->paciente)->get(route('mis-citas.pago.retorno', $pago))->assertSessionHas('status');
        $this->assertSame(Pago::PAGADO, $pago->fresh()->estado_pago);
        $this->assertSame(Cita::CONFIRMADA, $this->cita->fresh()->estado);
    }

    public function test_stripe_sin_pagar_o_con_otro_importe_no_registra_el_pago(): void
    {
        $this->usarStripe();
        $this->fingirStripe();
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita));
        $pago = Pago::sole();

        $this->actingAs($this->paciente)->get(route('mis-citas.pago.retorno', $pago))->assertSessionHas('error');
        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado_pago);

        // Un importe distinto del esperado no se da por bueno.
        $this->aviso('checkout.session.completed', ['payment_status' => 'paid', 'amount_total' => 100])->assertOk();
        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado_pago);
        $this->assertStringContainsString('NO registrado', Bitacora::where('modulo', 'Pagos')->sole()->detalle);
    }

    public function test_sin_clave_de_stripe_el_pago_en_linea_no_esta_disponible(): void
    {
        config(['pagos.pasarela' => 'stripe', 'pagos.stripe.secret' => null]);

        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))
            ->assertSessionHas('error', 'El pago en línea no está disponible en este momento. Puedes pagar en recepción.');
        $this->assertSame(0, Pago::count());
    }

    public function test_si_stripe_falla_el_intento_queda_fallido(): void
    {
        $this->usarStripe();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid currency']], 400)]);

        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))
            ->assertSessionHas('error', 'No se pudo conectar con la pasarela de pago. Inténtalo más tarde o paga en recepción.');
        $this->assertSame(Pago::FALLIDO, Pago::sole()->estado_pago);
    }

    public function test_el_aviso_firmado_de_stripe_registra_el_pago_una_sola_vez(): void
    {
        $this->usarStripe();
        $this->fingirStripe();
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita));
        $pago = Pago::sole();

        // Sin firma o con otra clave: se rechaza.
        $this->postJson(route('pagos.stripe.webhook'), ['type' => 'checkout.session.completed'])->assertStatus(400);
        $this->aviso('checkout.session.completed', ['payment_status' => 'paid'], 'whsec_otra')->assertStatus(400);
        $this->assertSame(Pago::PENDIENTE, $pago->fresh()->estado_pago);

        $this->aviso('checkout.session.completed', ['payment_status' => 'paid'])->assertOk();
        $this->aviso('checkout.session.completed', ['payment_status' => 'paid'])->assertOk();

        $this->assertSame(Pago::PAGADO, $pago->fresh()->estado_pago);
        $this->assertSame(1, Bitacora::where('modulo', 'Pagos')->count());
        $this->assertSame(1, DB::table('historial_estado_cita')->where('id_cita', $this->cita->id_cita)->where('estado_nuevo', 'Confirmada')->count());
    }

    public function test_el_aviso_de_sesion_expirada_marca_el_intento_fallido(): void
    {
        $this->usarStripe();
        $this->fingirStripe();
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita));

        $this->aviso('checkout.session.expired', ['payment_status' => 'unpaid', 'status' => 'expired'])->assertOk();
        $this->assertSame(Pago::FALLIDO, Pago::sole()->estado_pago);
    }

    public function test_la_firma_caduca_y_admite_varias_firmas(): void
    {
        $firma = hash_hmac('sha256', '1000.{}', 's');
        $this->assertTrue(PasarelaStripe::firmaValida('{}', "t=1000,v1=otra,v1={$firma}", 's', 1100));
        $this->assertFalse(PasarelaStripe::firmaValida('{}', "t=1000,v1={$firma}", 's', 1000 + 301));
        $this->assertFalse(PasarelaStripe::firmaValida('{}', "t=1000,v1={$firma}", null, 1000));
        $this->assertFalse(PasarelaStripe::firmaValida('{}', null, 's', 1000));
    }

    // --- Ventanilla --------------------------------------------------------------------

    public function test_recepcion_registra_el_cobro_en_ventanilla(): void
    {
        $this->actingAs($this->recepcion)->get(route('citas.show', $this->cita))->assertOk()
            ->assertSee('Registrar pago de Bs 200,00')->assertSee('Sin pagos ni intentos de pago registrados.');

        $this->actingAs($this->recepcion)->post(route('citas.pago', $this->cita), ['metodo' => 'Efectivo', 'comprobante' => 'R-001'])
            ->assertRedirect(route('citas.show', $this->cita))->assertSessionHas('status', "Pago de Bs 200,00 registrado para la cita {$this->cita->codigo}.");

        $pago = $this->pagado();
        $this->assertSame(['Efectivo', 'R-001', '200.00'], [$pago->metodo_de_pago, $pago->id_transaccion, $pago->monto]);
        $this->assertSame(Cita::CONFIRMADA, $this->cita->fresh()->estado);
        $evento = Bitacora::where('modulo', 'Pagos')->sole();
        $this->assertSame((int) $this->recepcion->id, (int) $evento->id_usuario);

        // No se cobra dos veces, ni en ventanilla ni en línea.
        $this->actingAs($this->recepcion)->post(route('citas.pago', $this->cita), ['metodo' => 'QR bancario'])->assertSessionHas('error');
        $this->actingAs($this->paciente)->post(route('mis-citas.pagar', $this->cita))->assertSessionHas('error');
        $this->assertSame(1, Pago::count());
        $this->actingAs($this->recepcion)->get(route('citas.show', $this->cita))->assertSee('Cita pagada')->assertDontSee('Registrar pago de');
    }

    public function test_ventanilla_valida_metodo_estado_y_permisos(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.pago', $this->cita), ['metodo' => 'Bitcoin'])->assertSessionHasErrorsIn('pago', 'metodo');
        $this->actingAs($this->medico)->post(route('citas.pago', $this->cita), ['metodo' => 'Efectivo'])->assertForbidden();
        $this->actingAs($this->paciente)->post(route('citas.pago', $this->cita), ['metodo' => 'Efectivo'])->assertForbidden();
        $this->assertSame(0, Pago::count());

        // Una cita ya atendida se puede cobrar; una cancelada, no.
        $atendida = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, now()->subDay()->toDateString(), '08:00', '08:30', 'Atendida');
        $this->actingAs($this->recepcion)->post(route('citas.pago', $atendida), ['metodo' => 'QR bancario'])->assertSessionHas('status');
        $this->assertSame(Cita::ATENDIDA, Cita::find($atendida)->estado);
        $cancelada = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->proximoLunes()->toDateString(), '09:00', '09:30', 'Cancelada');
        $this->actingAs($this->recepcion)->post(route('citas.pago', $cancelada), ['metodo' => 'QR bancario'])->assertSessionHas('error');
    }

    public function test_al_cancelar_una_cita_pagada_se_avisa_que_no_hay_devolucion_automatica(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.pago', $this->cita), ['metodo' => 'Efectivo']);

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $this->cita))->assertSee('el importe no se devuelve automáticamente');
        $this->actingAs($this->recepcion)->get(route('citas.show', $this->cita))->assertSee('la devolución no es automática');
    }
}
