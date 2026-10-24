<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Disponibilidad;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

class HorariosTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->medico = $this->medicoCon('medico01', $this->especialidadActiva(), null);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge(['id_medico' => $this->medico->id, 'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_final' => '12:00', 'duracion_cita' => 30], $cambios);
    }

    public function test_acceso_por_perfil_y_permisos(): void
    {
        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $paciente = $this->pacienteDe('paciente01');

        $this->get(route('horarios.index'))->assertRedirect(route('login'));
        $this->actingAs($recepcion)->get(route('horarios.index'))->assertOk()->assertDontSee('Registrar horario');
        $this->actingAs($recepcion)->post(route('horarios.store'), $this->datos())->assertForbidden();
        $this->actingAs($paciente)->get(route('horarios.index'))->assertForbidden();
        $this->actingAs($this->medico)->get(route('horarios.index'))->assertForbidden();
    }

    public function test_registra_un_horario_y_lo_lista(): void
    {
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos())
            ->assertRedirect(route('horarios.index', ['medico' => $this->medico->id]))
            ->assertSessionHas('status', 'Horario de atención registrado correctamente.');

        $this->assertDatabaseHas('horario_atencion', ['id_medico' => $this->medico->id, 'dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_final' => '12:00:00', 'duracion_cita' => 30]);

        $this->actingAs($this->admin)->get(route('horarios.index', ['medico' => $this->medico->id, 'dia' => 1]))
            ->assertOk()->assertSee('Lunes')->assertSee('08:00–12:00')->assertSee('30 min');
        $this->actingAs($this->admin)->get(route('horarios.index', ['dia' => 2]))->assertSee('No hay horarios con esos filtros.');
    }

    public function test_validaciones_con_mensajes_en_espanol(): void
    {
        $inactivo = $this->medicoCon('inactivo', null, null, 'Inactivo');

        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['dia_semana' => 8, 'hora_inicio' => '8am', 'duracion_cita' => 2]))
            ->assertSessionHasErrors([
                'dia_semana' => 'El valor seleccionado en día de la semana no es válido.',
                'hora_inicio' => 'La hora de inicio debe tener el formato HH:MM.',
                'duracion_cita' => 'La duración de la cita debe ser de al menos 5 minutos.',
            ]);

        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['hora_inicio' => '12:00', 'hora_final' => '08:00']))
            ->assertSessionHasErrors(['hora_final' => 'La hora de fin debe ser posterior a la hora de inicio.']);

        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['hora_final' => '08:20', 'duracion_cita' => 30]))
            ->assertSessionHasErrors(['duracion_cita' => 'La duración de la cita no cabe entre la hora de inicio y la de fin.']);

        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['id_medico' => $inactivo->id]))
            ->assertSessionHasErrors(['id_medico' => 'Solo se pueden registrar horarios de médicos activos.']);

        $this->assertDatabaseCount('horario_atencion', 0);
    }

    public function test_no_permite_horarios_superpuestos_del_mismo_medico(): void
    {
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos());

        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['hora_inicio' => '11:00', 'hora_final' => '13:00']))
            ->assertSessionHasErrors(['hora_inicio' => 'El horario se superpone con otro horario del mismo médico ese día.']);

        // Otro día, o justo a continuación, sí.
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['dia_semana' => 2]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['hora_inicio' => '12:00', 'hora_final' => '14:00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('horario_atencion', 3);
    }

    public function test_edita_y_elimina_un_horario_sin_citas_pendientes(): void
    {
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos());
        $id = DB::table('horario_atencion')->value('id_horario');

        $this->actingAs($this->admin)->put(route('horarios.update', $id), $this->datos(['hora_final' => '11:00', 'duracion_cita' => 20]))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Horario de atención actualizado correctamente.');
        $this->assertDatabaseHas('horario_atencion', ['id_horario' => $id, 'hora_final' => '11:00:00', 'duracion_cita' => 20]);

        $this->actingAs($this->admin)->get(route('horarios.edit', $id))->assertSee('Eliminar definitivamente');
        $this->actingAs($this->admin)->delete(route('horarios.destroy', $id))->assertSessionHas('status', 'Horario de atención eliminado correctamente.');
        $this->assertDatabaseCount('horario_atencion', 0);
    }

    public function test_no_modifica_ni_elimina_un_horario_con_citas_pendientes(): void
    {
        $especialidad = \App\Models\Especialidad::first();
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos());
        $id = DB::table('horario_atencion')->value('id_horario');
        $paciente = $this->pacienteDe('paciente01');
        $this->insertarCita($paciente, $this->medico, $especialidad, $this->proximoLunes()->toDateString(), '08:30', '09:00');

        $this->actingAs($this->admin)->get(route('horarios.edit', $id))->assertSee('tiene 1 cita pendiente')->assertDontSee('Eliminar definitivamente');

        $this->actingAs($this->admin)->put(route('horarios.update', $id), $this->datos(['hora_inicio' => '09:00']))
            ->assertSessionHasErrors(['hora_inicio' => 'No se puede modificar: hay 1 cita pendiente en este horario. Cancélalas antes.']);
        $this->actingAs($this->admin)->delete(route('horarios.destroy', $id))
            ->assertSessionHas('error', 'No se puede eliminar el horario: hay 1 cita pendiente en él. Cancélalas antes.');

        $this->assertDatabaseHas('horario_atencion', ['id_horario' => $id, 'hora_inicio' => '08:00:00']);

        // Una cita cancelada no lo impide.
        DB::table('citas')->update(['estado' => 'Cancelada']);
        $this->actingAs($this->admin)->delete(route('horarios.destroy', $id))->assertSessionHas('status');
        $this->assertDatabaseCount('citas', 1);
    }

    public function test_edita_un_horario_recurrente_de_sabado_aunque_el_ultimo_sabado_ya_paso(): void
    {
        // Lunes 26/10/2026: el sábado 24 ya pasó, pero el horario es semanal (dia_semana = 6), no una fecha.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'America/La_Paz'));
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['dia_semana' => 6, 'hora_inicio' => '12:15', 'hora_final' => '12:45']))
            ->assertSessionHasNoErrors();
        $id = DB::table('horario_atencion')->value('id_horario');
        $paciente = $this->pacienteDe('paciente01');
        $pasada = $this->insertarCita($paciente, $this->medico, \App\Models\Especialidad::first(), '2026-10-24', '12:15', '12:45', 'Atendida');

        $this->actingAs($this->admin)->get(route('horarios.edit', $id))->assertOk()->assertDontSee('cita pendiente');
        $this->actingAs($this->admin)->put(route('horarios.update', $id), $this->datos(['dia_semana' => 6, 'hora_inicio' => '09:00', 'hora_final' => '13:00']))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Horario de atención actualizado correctamente.');

        $this->assertDatabaseHas('horario_atencion', ['id_horario' => $id, 'dia_semana' => 6, 'hora_inicio' => '09:00:00', 'hora_final' => '13:00:00']);
        // La cita del sábado pasado se conserva tal cual.
        $this->assertDatabaseHas('citas', ['id_cita' => $pasada, 'fecha_cita' => '2026-10-24', 'hora_inicio' => '12:15:00', 'estado' => 'Atendida']);

        // Las validaciones del horario siguen aplicándose al editarlo.
        $this->actingAs($this->admin)->put(route('horarios.update', $id), $this->datos(['dia_semana' => 6, 'hora_inicio' => '13:00', 'hora_final' => '12:00']))
            ->assertSessionHasErrors(['hora_final' => 'La hora de fin debe ser posterior a la hora de inicio.']);
    }

    public function test_las_franjas_de_hoy_usan_la_hora_local_de_la_clinica(): void
    {
        $this->assertSame('America/La_Paz', config('app.timezone'));

        // Sábado 24/10/2026, 16:06 UTC = 12:06 en Bolivia.
        $this->travelTo(CarbonImmutable::parse('2026-10-24 16:06', 'UTC'));
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos(['dia_semana' => 6, 'hora_inicio' => '11:00', 'hora_final' => '13:00']));
        $hoy = CarbonImmutable::today();
        $this->assertSame('2026-10-24', $hoy->toDateString());

        $estados = app(Disponibilidad::class)->franjas($this->medico->id, $hoy)->pluck('estado', 'inicio')->all();
        $this->assertSame(['11:00' => 'pasada', '11:30' => 'pasada', '12:00' => 'pasada', '12:30' => 'libre'], $estados);

        $this->actingAs($this->admin)->get(route('agenda.index', ['medico' => $this->medico->id, 'fecha' => '2026-10-24']))
            ->assertOk()->assertSee('Libre');

        // Las restricciones de fecha concreta siguen: no se registra una cita en una franja ya pasada,
        // sí en una de hoy que aún no llegó.
        $especialidad = \App\Models\Especialidad::first();
        $paciente = $this->pacienteDe('paciente01');
        $cita = ['id_paciente' => $paciente->id, 'id_especialidad' => $especialidad->id_especialidad, 'fecha' => '2026-10-24'];
        $this->actingAs($this->admin)->post(route('citas.store'), $cita + ['franja' => $this->medico->id . '|11:30'])->assertSessionHasErrors('franja');
        $this->actingAs($this->admin)->post(route('citas.store'), $cita + ['franja' => $this->medico->id . '|12:30'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('citas.store'), array_merge($cita, ['fecha' => '2026-10-23', 'franja' => $this->medico->id . '|12:30']))->assertSessionHasErrors('fecha');
        $this->assertDatabaseCount('citas', 1);
    }

    public function test_las_vistas_no_contienen_javascript(): void
    {
        $this->actingAs($this->admin)->post(route('horarios.store'), $this->datos());
        $id = DB::table('horario_atencion')->value('id_horario');

        foreach ([route('horarios.index'), route('horarios.create'), route('horarios.edit', $id)] as $url) {
            $this->sinJavascript($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
        }
    }
}
