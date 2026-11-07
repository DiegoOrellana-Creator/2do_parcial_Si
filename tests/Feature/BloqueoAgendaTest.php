<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\User;
use App\Services\Disponibilidad;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Bloqueos de agenda. El médico de prueba atiende los lunes de 08:00 a 10:00, cada 30 minutos.
 */
class BloqueoAgendaTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $recepcion;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    private string $lunes;

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
        $this->lunes = $this->proximoLunes()->toDateString();
    }

    private function datos(array $cambios = []): array
    {
        return array_merge(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:00', 'hora_final' => '10:00', 'motivo' => 'Congreso médico'], $cambios);
    }

    public function test_acceso_por_perfil(): void
    {
        $bloqueo = BloqueoAgenda::create(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:00:00', 'hora_final' => '10:00:00', 'motivo' => 'x']);

        $this->get(route('agenda.bloqueos.index'))->assertRedirect(route('login'));

        foreach ([$this->paciente, $this->medico, $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get(route('agenda.bloqueos.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('agenda.bloqueos.create'))->assertForbidden();
            $this->actingAs($otro)->post(route('agenda.bloqueos.store'), $this->datos(['fecha' => now()->addWeeks(2)->toDateString()]))->assertForbidden();
            $this->actingAs($otro)->delete(route('agenda.bloqueos.destroy', $bloqueo))->assertForbidden();
        }
        $this->assertSame(1, BloqueoAgenda::count());

        foreach ([$this->recepcion, $this->admin->fresh()] as $personal) {
            $this->actingAs($personal)->get(route('agenda.bloqueos.index'))->assertOk()->assertSee('Prueba, Medico01', false)->assertSee(route('agenda.bloqueos.destroy', $bloqueo));
            $this->actingAs($personal)->get(route('agenda.bloqueos.create'))->assertOk();
        }
    }

    public function test_registra_y_saca_las_franjas_de_la_disponibilidad(): void
    {
        $this->actingAs($this->recepcion)->post(route('agenda.bloqueos.store'), $this->datos())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('agenda.bloqueos.index', ['medico' => $this->medico->id]))
            ->assertSessionHas('status', 'Bloqueo de agenda registrado correctamente.');

        $this->assertDatabaseHas('bloqueo_agenda', ['id_medico' => $this->medico->id, 'hora_inicio' => '09:00:00', 'hora_final' => '10:00:00', 'motivo' => 'Congreso médico']);
        $evento = Bitacora::sole();
        $this->assertSame(['Creación', 'Agenda', $this->recepcion->id], [$evento->accion, $evento->modulo, (int) $evento->id_usuario]);
        $this->assertStringContainsString('Medico01 Prueba. Motivo: Congreso médico', $evento->detalle);

        $estados = app(Disponibilidad::class)->franjas($this->medico->id, CarbonImmutable::parse($this->lunes))->pluck('estado', 'inicio')->all();
        $this->assertSame(['08:00' => 'libre', '08:30' => 'libre', '09:00' => 'bloqueada', '09:30' => 'bloqueada'], $estados);

        // Ni el personal ni el paciente pueden registrar una cita ahí.
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), [
            'id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|09:00',
        ])->assertSessionHasErrors(['franja' => 'El médico tiene la agenda bloqueada en esa franja.']);
        $this->assertSame(0, Cita::count());
    }

    public function test_quitar_un_bloqueo_libera_las_franjas(): void
    {
        $this->actingAs($this->admin->fresh())->post(route('agenda.bloqueos.store'), $this->datos());
        $bloqueo = BloqueoAgenda::sole();

        $this->actingAs($this->admin->fresh())->delete(route('agenda.bloqueos.destroy', $bloqueo))
            ->assertSessionHas('status', 'Bloqueo de agenda quitado. Esas franjas vuelven a estar disponibles.');

        $this->assertSame(0, BloqueoAgenda::count());
        $this->assertDatabaseHas('bitacora', ['accion' => 'Eliminación', 'modulo' => 'Agenda', 'id_registro' => $bloqueo->id_bloqueo]);
        $estados = app(Disponibilidad::class)->franjas($this->medico->id, CarbonImmutable::parse($this->lunes))->pluck('estado')->unique()->all();
        $this->assertSame(['libre'], $estados);
    }

    public function test_un_bloqueo_que_ya_termino_no_se_quita(): void
    {
        $pasado = now()->subWeek()->startOfWeek()->toDateString();
        $bloqueo = BloqueoAgenda::create(['id_medico' => $this->medico->id, 'fecha' => $pasado, 'hora_inicio' => '09:00:00', 'hora_final' => '10:00:00', 'motivo' => 'Antiguo']);

        $this->actingAs($this->recepcion)->get(route('agenda.bloqueos.index', ['desde' => $pasado]))
            ->assertOk()->assertSee('Antiguo')->assertSee('Finalizado')->assertDontSee(route('agenda.bloqueos.destroy', $bloqueo));
        $this->actingAs($this->recepcion)->delete(route('agenda.bloqueos.destroy', $bloqueo))
            ->assertSessionHas('error', 'Un bloqueo que ya terminó no se puede quitar: se conserva como historial.');

        $this->assertSame(1, BloqueoAgenda::count());
        // Por defecto la lista empieza hoy.
        $this->actingAs($this->recepcion)->get(route('agenda.bloqueos.index'))->assertDontSee('Antiguo')->assertSee('No hay bloqueos de agenda con esos filtros.');
    }

    public function test_no_bloquea_periodos_con_citas_pendientes_ni_superpuestos(): void
    {
        $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00');

        $this->actingAs($this->recepcion)->post(route('agenda.bloqueos.store'), $this->datos(['hora_inicio' => '08:00', 'hora_final' => '09:30']))
            ->assertSessionHasErrors(['hora_inicio' => 'No se puede bloquear: hay 1 cita pendiente en ese periodo. Cancélalas o reprográmalas antes.']);
        $this->assertSame(0, BloqueoAgenda::count());

        // Una cita cancelada no lo impide, y justo a continuación tampoco hay choque.
        $this->insertarCita($this->pacienteDe('otro'), $this->medico, $this->cardiologia, $this->lunes, '09:30', '10:00', Cita::CANCELADA);
        $this->actingAs($this->recepcion)->post(route('agenda.bloqueos.store'), $this->datos())->assertSessionHasNoErrors();

        $this->actingAs($this->recepcion)->post(route('agenda.bloqueos.store'), $this->datos(['hora_inicio' => '09:30', 'hora_final' => '11:00']))
            ->assertSessionHasErrors(['hora_inicio' => 'Ese periodo se superpone con otro bloqueo del médico.']);
        $this->actingAs($this->recepcion)->post(route('agenda.bloqueos.store'), $this->datos(['hora_inicio' => '10:00', 'hora_final' => '11:00']))->assertSessionHasNoErrors();

        $this->assertSame(2, BloqueoAgenda::count());
    }

    public function test_valida_con_mensajes_en_espanol(): void
    {
        $inactivo = $this->medicoCon('inactivo', $this->cardiologia, null, 'Inactivo');
        $enviar = fn (array $cambios) => $this->actingAs($this->recepcion)->post(route('agenda.bloqueos.store'), $this->datos($cambios));

        $enviar(['id_medico' => '', 'motivo' => ''])->assertSessionHasErrors([
            'id_medico' => 'El campo médico es obligatorio.',
            'motivo' => 'El campo motivo es obligatorio.',
        ]);
        $enviar(['id_medico' => $inactivo->id])->assertSessionHasErrors(['id_medico' => 'Solo se pueden bloquear agendas de médicos activos.']);
        $enviar(['id_medico' => 99999])->assertSessionHasErrors(['id_medico' => 'El médico seleccionado no existe.']);
        $enviar(['fecha' => now()->subDay()->toDateString()])->assertSessionHasErrors(['fecha' => 'La fecha no puede ser anterior a hoy.']);
        $enviar(['hora_inicio' => '10:00', 'hora_final' => '09:00'])->assertSessionHasErrors(['hora_final' => 'La hora de fin debe ser posterior a la hora de inicio.']);
        $enviar(['hora_inicio' => '8am'])->assertSessionHasErrors(['hora_inicio' => 'La hora de inicio debe tener el formato HH:MM.']);
        $enviar(['motivo' => str_repeat('a', 501)])->assertSessionHasErrors(['motivo' => 'El motivo no debe superar los 500 caracteres.']);

        $this->travelTo(CarbonImmutable::parse($this->lunes . ' 12:00', 'America/La_Paz'));
        $enviar(['hora_inicio' => '08:00', 'hora_final' => '09:00'])->assertSessionHasErrors(['hora_final' => 'El bloqueo debe terminar en el futuro.']);

        $this->assertSame(0, BloqueoAgenda::count());
        $this->assertSame(0, Bitacora::count());
    }

    public function test_pantallas_y_enlace_desde_la_agenda(): void
    {
        $this->actingAs($this->recepcion)->get(route('agenda.index', ['medico' => $this->medico->id]))
            ->assertOk()->assertSee(route('agenda.bloqueos.index', ['medico' => $this->medico->id]));
        $this->actingAs($this->medico)->get(route('agenda.index'))->assertOk()->assertDontSee('Bloqueos de agenda');

        foreach ([route('agenda.bloqueos.index'), route('agenda.bloqueos.create', ['medico' => $this->medico->id])] as $url) {
            $html = $this->actingAs($this->recepcion)->get($url)->assertOk()->getContent();
            $this->sinJavascript($html);
            preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $menu);
            preg_match_all('#aria-current="page"\s+class="nav-link is-active">.*?<span class="nav-link__label">(.*?)</span>#s', $menu[1], $activos);
            $this->assertSame(['Agenda'], $activos[1]);
        }

        // Filtra por médico.
        $otro = $this->medicoCon('medico02', $this->cardiologia);
        DB::table('bloqueo_agenda')->insert(['id_medico' => $otro->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:00:00', 'hora_final' => '10:00:00', 'motivo' => 'Del otro']);
        $this->actingAs($this->recepcion)->get(route('agenda.bloqueos.index', ['medico' => $this->medico->id]))->assertDontSee('Del otro');
        $this->actingAs($this->recepcion)->get(route('agenda.bloqueos.index', ['medico' => $otro->id]))->assertSee('Del otro');
    }
}
