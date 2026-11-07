<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * «Mis citas» del médico: solo consulta y solo sus propias citas.
 */
class MisConsultasTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private Especialidad $cardiologia;

    private User $medico;

    private User $otroMedico;

    private User $paciente;

    private string $futuro;

    private string $pasado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->otroMedico = $this->medicoCon('medico02', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->futuro = $this->proximoLunes()->toDateString();
        $this->pasado = now()->subWeek()->startOfWeek()->toDateString();
    }

    private function cita(User $medico, string $fecha, string $estado = Cita::PROGRAMADA, string $inicio = '08:00', string $fin = '08:30'): Cita
    {
        return Cita::findOrFail($this->insertarCita($this->paciente, $medico, $this->cardiologia, $fecha, $inicio, $fin, $estado));
    }

    public function test_solo_los_medicos_entran(): void
    {
        $cita = $this->cita($this->medico, $this->futuro);

        $this->get(route('consultas.index'))->assertRedirect(route('login'));
        $this->get(route('consultas.show', $cita))->assertRedirect(route('login'));

        foreach ([User::where('username', 'admin')->first(), $this->cuenta('recepcion', 'Recepcionista'), $this->paciente, $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get(route('consultas.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('consultas.show', $cita))->assertForbidden();
        }

        $this->actingAs($this->medico)->get(route('consultas.index'))->assertOk();
    }

    public function test_lista_solo_sus_citas_separadas_en_proximas_y_anteriores(): void
    {
        $proxima = $this->cita($this->medico, $this->futuro, Cita::CONFIRMADA);
        $anterior = $this->cita($this->medico, $this->pasado, Cita::NO_ASISTIO);
        $ajena = $this->cita($this->otroMedico, $this->futuro, Cita::PROGRAMADA, '09:00', '09:30');

        $html = $this->actingAs($this->medico)->get(route('consultas.index'))->assertOk()
            ->assertSee($proxima->codigo)->assertSee($anterior->codigo)->assertDontSee($ajena->codigo)
            ->assertSee('Paciente01 Prueba')->assertSee('Cardiología')->assertSee('Confirmada')->assertSee('No asistió')
            ->getContent();

        $this->assertLessThan(strpos($html, 'Citas anteriores'), strpos($html, $proxima->codigo));
        $this->assertGreaterThan(strpos($html, 'Citas anteriores'), strpos($html, $anterior->codigo));
        $this->sinJavascript($html);
    }

    public function test_filtra_por_estado_y_lo_ignora_si_no_es_valido(): void
    {
        $programada = $this->cita($this->medico, $this->futuro);
        $cancelada = $this->cita($this->medico, $this->futuro, Cita::CANCELADA, '08:30', '09:00');

        $this->actingAs($this->medico)->get(route('consultas.index', ['estado' => 'Cancelada']))
            ->assertSee($cancelada->codigo)->assertDontSee($programada->codigo);
        $this->actingAs($this->medico)->get(route('consultas.index', ['estado' => 'x']))
            ->assertSee($cancelada->codigo)->assertSee($programada->codigo);
    }

    public function test_pagina_cada_lista_por_separado(): void
    {
        foreach (range(0, 16) as $n) {
            $this->cita($this->medico, now()->addDays($n + 1)->toDateString());
        }
        $anterior = $this->cita($this->medico, $this->pasado);

        $this->actingAs($this->medico)->get(route('consultas.index'))
            ->assertSee('Mostrando 1–15 de 17 citas')->assertSee($anterior->codigo);
        $this->actingAs($this->medico)->get(route('consultas.index', ['proximas' => 2]))
            ->assertSee('Mostrando 16–17 de 17 citas')->assertSee($anterior->codigo);
    }

    public function test_el_detalle_muestra_su_cita_y_oculta_la_de_otro_medico(): void
    {
        $cita = $this->cita($this->medico, $this->futuro);
        DB::table('citas')->where('id_cita', $cita->id_cita)->update(['motivo' => 'Dolor de pecho']);
        DB::table('historial_estado_cita')->insert(['id_cita' => $cita->id_cita, 'estado_nuevo' => 'Programada', 'fecha_hora' => now(), 'motivo' => 'Cita registrada.']);
        $ajena = $this->cita($this->otroMedico, $this->futuro, Cita::PROGRAMADA, '09:00', '09:30');

        $html = $this->actingAs($this->medico)->get(route('consultas.show', $cita))->assertOk()
            ->assertSee('Paciente01 Prueba')->assertSee('Dolor de pecho')->assertSee('Cita registrada.')
            ->getContent();
        $this->sinJavascript($html);

        // Solo consulta: ninguna acción sobre la cita.
        $this->assertStringNotContainsString('name="_method"', $html);
        $this->assertStringNotContainsString('/citas/' . $cita->id_cita, $html);

        $this->actingAs($this->medico)->get(route('consultas.show', $ajena))->assertNotFound();
    }

    public function test_el_medico_sigue_sin_poder_modificar_citas(): void
    {
        $futura = $this->cita($this->medico, $this->futuro);
        $pasada = $this->cita($this->medico, $this->pasado);

        $this->actingAs($this->medico)->get(route('citas.show', $futura))->assertForbidden();
        $this->actingAs($this->medico)->patch(route('citas.cancelar', $futura), ['motivo_cancelacion' => 'x'])->assertForbidden();
        $this->actingAs($this->medico)->patch(route('citas.atendida', $pasada))->assertForbidden();

        $this->assertSame([Cita::PROGRAMADA, Cita::PROGRAMADA], [$futura->fresh()->estado, $pasada->fresh()->estado]);
    }

    public function test_el_medico_registra_la_asistencia_de_sus_citas(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $atendida = $this->cita($this->medico, $this->pasado);
        $falta = $this->cita($this->medico, $this->pasado, Cita::CONFIRMADA, '09:00', '09:30');

        $html = $this->actingAs($this->medico)->get(route('consultas.show', $atendida))->assertOk()
            ->assertSee('Marcar atendida')->assertSee('No asistió')->getContent();
        $this->sinJavascript($html);

        $this->actingAs($this->medico)->patch(route('consultas.atendida', $atendida))
            ->assertRedirect(route('consultas.show', $atendida))->assertSessionHas('status');
        $this->assertSame(Cita::ATENDIDA, $atendida->fresh()->estado);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $atendida->id_cita, 'estado_nuevo' => Cita::ATENDIDA, 'registrado_por' => $this->medico->id]);

        $this->actingAs($this->medico)->patch(route('consultas.inasistencia', $falta))->assertSessionHas('status');
        $this->assertSame(Cita::NO_ASISTIO, $falta->fresh()->estado);
        $this->assertSame(1, (int) DB::table('paciente')->where('id_paciente', $this->paciente->id)->value('inasistencia'));
        $this->assertDatabaseHas('notificacion', ['id_usuario' => $this->paciente->id, 'tipo' => 'Inasistencia']);

        // Ya registrada, no se vuelve a cambiar.
        $this->actingAs($this->medico)->patch(route('consultas.inasistencia', $atendida))->assertSessionHas('error');
        $this->assertSame(Cita::ATENDIDA, $atendida->fresh()->estado);
        $this->actingAs($this->medico)->get(route('consultas.show', $atendida))->assertDontSee('Marcar atendida');
    }

    public function test_el_medico_no_registra_asistencia_antes_de_hora_ni_de_citas_ajenas(): void
    {
        $futura = $this->cita($this->medico, $this->futuro);
        $ajena = $this->cita($this->otroMedico, $this->pasado);

        $this->actingAs($this->medico)->get(route('consultas.show', $futura))->assertDontSee('Marcar atendida')
            ->assertSee('La asistencia se registra cuando llegue la hora de la cita.');
        $this->actingAs($this->medico)->patch(route('consultas.atendida', $futura))->assertSessionHas('error');
        $this->actingAs($this->medico)->patch(route('consultas.atendida', $ajena))->assertNotFound();
        $this->actingAs($this->medico)->patch(route('consultas.inasistencia', $ajena))->assertNotFound();

        // Solo el perfil médico usa estas rutas.
        $this->actingAs($this->paciente)->patch(route('consultas.atendida', $ajena))->assertForbidden();
        $this->assertSame([Cita::PROGRAMADA, Cita::PROGRAMADA], [$futura->fresh()->estado, $ajena->fresh()->estado]);
    }

    public function test_menu_e_inicio_del_medico_enlazan_mis_citas(): void
    {
        $html = $this->actingAs($this->medico)->get(route('dashboard'))->assertOk()
            ->assertDontSee('todavía no están habilitadas')->assertDontSee('Pronto')
            ->getContent();
        $this->assertStringContainsString('href="' . route('consultas.index') . '"', $html);

        $html = $this->actingAs($this->medico)->get(route('consultas.index'))->getContent();
        preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $menu);
        preg_match_all('#aria-current="page"\s+class="nav-link is-active">.*?<span class="nav-link__label">(.*?)</span>#s', $menu[1], $activos);
        $this->assertSame(['Mis citas'], $activos[1]);
    }
}
