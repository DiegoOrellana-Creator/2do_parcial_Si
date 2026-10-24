<?php

namespace Tests\Feature;

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
 * Disponibilidad, registro y cancelación de citas.
 */
class CitasTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);          // lunes 08:00–10:00, cada 30 min
        $this->paciente = $this->pacienteDe('paciente01');
        $this->lunes = $this->proximoLunes()->toDateString();
    }

    private function registrar(User $como, array $cambios = [])
    {
        return $this->actingAs($como)->post(route('citas.store'), array_merge([
            'id_paciente' => $this->paciente->id,
            'id_especialidad' => $this->cardiologia->id_especialidad,
            'fecha' => $this->lunes,
            'franja' => $this->medico->id . '|08:00',
            'motivo' => 'Control',
        ], $cambios));
    }

    // --- Disponibilidad ------------------------------------------------------

    public function test_calcula_las_franjas_libres_sin_citas_ni_bloqueos(): void
    {
        $servicio = app(Disponibilidad::class);
        $fecha = CarbonImmutable::parse($this->lunes);

        $this->assertSame(['08:00', '08:30', '09:00', '09:30'], $servicio->franjas($this->medico->id, $fecha)->pluck('inicio')->all());

        $otro = $this->pacienteDe('paciente02');
        $this->insertarCita($otro, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00');
        $this->insertarCita($otro, $this->medico, $this->cardiologia, $this->lunes, '08:00', '08:30', 'Cancelada');
        DB::table('bloqueo_agenda')->insert(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:30:00', 'hora_final' => '10:00:00', 'motivo' => 'Congreso']);

        $estados = $servicio->franjas($this->medico->id, $fecha)->pluck('estado', 'inicio')->all();
        $this->assertSame(['08:00' => 'libre', '08:30' => 'ocupada', '09:00' => 'libre', '09:30' => 'bloqueada'], $estados);

        // Un martes no atiende.
        $this->assertTrue($servicio->franjas($this->medico->id, $fecha->addDay())->isEmpty());
    }

    public function test_las_franjas_de_hoy_ya_pasadas_no_estan_libres(): void
    {
        $this->travelTo(CarbonImmutable::parse($this->lunes . ' 09:10'));

        $estados = app(Disponibilidad::class)->franjas($this->medico->id, CarbonImmutable::today())->pluck('estado', 'inicio')->all();
        $this->assertSame(['08:00' => 'pasada', '08:30' => 'pasada', '09:00' => 'pasada', '09:30' => 'libre'], $estados);
    }

    public function test_pagina_de_disponibilidad(): void
    {
        $inactivo = $this->medicoCon('inactivo', $this->cardiologia, [1, '08:00', '09:00', 30], 'Inactivo');
        $this->insertarCita($this->pacienteDe('otro'), $this->medico, $this->cardiologia, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->admin)->get(route('disponibilidad.index'))->assertOk()->assertSee('Elige una especialidad y una fecha');

        $this->actingAs($this->admin)->get(route('disponibilidad.index', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes]))
            ->assertOk()
            ->assertSee('Medico01 Prueba')->assertDontSee('Inactivo Prueba')
            ->assertSee('3 de 4 franjas libres')
            ->assertSee('franja=' . $this->medico->id . '%7C08%3A00', false);

        $this->actingAs($this->admin)->get(route('disponibilidad.index', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => '2000-01-01']))
            ->assertSee('La fecha no puede ser anterior a hoy.');
        $this->actingAs($this->admin)->get(route('disponibilidad.index', ['especialidad' => 999, 'fecha' => $this->lunes]))
            ->assertSee('La especialidad seleccionada no existe o no está activa.');
    }

    // --- Registro ------------------------------------------------------------

    public function test_registra_una_cita_con_su_historial(): void
    {
        $respuesta = $this->registrar($this->admin)->assertSessionHasNoErrors();

        $cita = Cita::firstOrFail();
        $respuesta->assertRedirect(route('citas.show', $cita))->assertSessionHas('status', "Cita {$cita->codigo} registrada correctamente.");

        $this->assertMatchesRegularExpression('/^CIT-\d{8}-[A-Z0-9]{5}$/', $cita->codigo);
        $this->assertSame([$this->lunes, '08:00:00', '08:30:00', 'Programada', 'Control'], [$cita->fecha_cita, $cita->hora_inicio, $cita->hora_fin, $cita->estado, $cita->motivo]);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_anterior' => null, 'estado_nuevo' => 'Programada', 'registrado_por' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('citas.show', $cita))->assertOk()->assertSee($cita->codigo)->assertSee('Cita registrada.')->assertSee('Cancelar cita');
    }

    public function test_no_permite_dos_citas_en_la_misma_franja_del_medico(): void
    {
        $this->registrar($this->admin)->assertSessionHasNoErrors();

        $this->registrar($this->admin, ['id_paciente' => $this->pacienteDe('paciente02')->id])
            ->assertSessionHasErrors(['franja' => 'Esa franja ya está ocupada por otra cita.']);

        $this->assertDatabaseCount('citas', 1);
    }

    public function test_un_paciente_no_puede_tener_dos_citas_a_la_misma_hora(): void
    {
        $otroMedico = $this->medicoCon('medico02', $this->cardiologia);
        $this->registrar($this->admin)->assertSessionHasNoErrors();

        $this->registrar($this->admin, ['franja' => $otroMedico->id . '|08:00'])
            ->assertSessionHasErrors(['franja' => 'El paciente ya tiene otra cita a esa hora.']);

        $this->assertDatabaseCount('citas', 1);
    }

    public function test_rechaza_franjas_fuera_de_horario_desalineadas_bloqueadas_o_pasadas(): void
    {
        DB::table('bloqueo_agenda')->insert(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:00:00', 'hora_final' => '09:30:00']);

        foreach (['11:00', '08:10'] as $hora) {
            $this->registrar($this->admin, ['franja' => $this->medico->id . '|' . $hora])
                ->assertSessionHasErrors(['franja' => 'La hora elegida no corresponde a ningún horario de atención del médico.']);
        }
        $this->registrar($this->admin, ['franja' => $this->medico->id . '|09:00'])
            ->assertSessionHasErrors(['franja' => 'El médico tiene la agenda bloqueada en esa franja.']);
        $this->registrar($this->admin, ['fecha' => '2000-01-03'])
            ->assertSessionHasErrors(['fecha' => 'La fecha no puede ser anterior a hoy.']);
        $this->registrar($this->admin, ['franja' => 'cualquier-cosa'])
            ->assertSessionHasErrors(['franja' => 'La franja seleccionada no es válida.']);

        $this->assertDatabaseCount('citas', 0);
    }

    public function test_respeta_las_reglas_de_medico_especialidad_y_paciente(): void
    {
        $pediatria = $this->especialidadActiva('Pediatría');
        $this->registrar($this->admin, ['id_especialidad' => $pediatria->id_especialidad])
            ->assertSessionHasErrors(['franja' => 'El médico seleccionado no ejerce esa especialidad.']);

        $inactivo = $this->medicoCon('inactivo', $this->cardiologia, [1, '08:00', '10:00', 30], 'Inactivo');
        $this->registrar($this->admin, ['franja' => $inactivo->id . '|08:00'])
            ->assertSessionHasErrors(['franja' => 'El médico seleccionado no existe o no está activo.']);

        $this->registrar($this->admin, ['id_paciente' => $this->pacienteDe('bloqueado', true)->id])
            ->assertSessionHasErrors(['id_paciente' => 'El paciente está bloqueado y no puede registrar citas.']);

        $this->registrar($this->admin, ['id_paciente' => $this->pacienteDe('dormido', false, 'Inactivo')->id])
            ->assertSessionHasErrors(['id_paciente' => 'La cuenta del paciente no está activa.']);

        $this->cardiologia->update(['estado' => 'Inactivo']);
        $this->registrar($this->admin)->assertSessionHasErrors(['id_especialidad' => 'La especialidad no está activa.']);

        $this->assertDatabaseCount('citas', 0);
    }

    public function test_formulario_de_registro_en_dos_pasos(): void
    {
        $this->pacienteDe('bloqueado', true);

        $this->actingAs($this->admin)->get(route('citas.create'))->assertOk()->assertDontSee('2. Datos de la cita');

        $html = $this->actingAs($this->admin)
            ->get(route('citas.create', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'franja' => $this->medico->id . '|08:30']))
            ->assertOk()->assertSee('2. Datos de la cita')->assertSee('Paciente01')->assertDontSee('Bloqueado Prueba')
            ->getContent();

        $this->assertMatchesRegularExpression('#value="' . $this->medico->id . '\|08:30"\s+required\s+checked#', $html);
        $this->sinJavascript($html);
    }

    // --- Cancelación ---------------------------------------------------------

    public function test_cancela_una_cita_y_libera_la_franja(): void
    {
        $this->registrar($this->admin);
        $cita = Cita::first();

        $this->actingAs($this->admin)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => ''])
            ->assertSessionHasErrorsIn('cancelacion', ['motivo_cancelacion' => 'Indica el motivo de la cancelación.']);

        $this->actingAs($this->admin)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'El paciente avisó que no podrá asistir.'])
            ->assertRedirect(route('citas.show', $cita))
            ->assertSessionHas('status', "Cita {$cita->codigo} cancelada. La franja vuelve a estar disponible.");

        $cita->refresh();
        $this->assertSame(['Cancelada', 'El paciente avisó que no podrá asistir.', $this->admin->id], [$cita->estado, $cita->motivo_cancelacion, (int) $cita->cancelado_por]);
        $this->assertNotNull($cita->fecha_cancelacion);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_anterior' => 'Programada', 'estado_nuevo' => 'Cancelada']);
        $this->assertDatabaseCount('citas', 1);

        // La franja vuelve a estar libre y se puede volver a reservar.
        $this->registrar($this->admin, ['id_paciente' => $this->pacienteDe('paciente02')->id])->assertSessionHasNoErrors();

        // Una cita ya cancelada no se vuelve a cancelar.
        $this->actingAs($this->admin)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'Otra vez'])
            ->assertSessionHas('error');
    }

    public function test_no_cancela_citas_pasadas(): void
    {
        $id = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, '2026-01-05', '08:00', '08:30');

        $this->actingAs($this->admin)->get(route('citas.show', $id))->assertOk()->assertDontSee('Cancelar cita');
        $this->actingAs($this->admin)->patch(route('citas.cancelar', $id), ['motivo_cancelacion' => 'Tarde'])
            ->assertSessionHas('error', 'La cita ya no se puede cancelar: solo se cancelan citas programadas o confirmadas que todavía no empezaron.');
        $this->assertDatabaseHas('citas', ['id_cita' => $id, 'estado' => 'Programada']);
    }

    // --- Listado y acceso ------------------------------------------------------

    public function test_listado_con_filtros_y_estado_vacio(): void
    {
        $this->actingAs($this->admin)->get(route('citas.index'))->assertOk()->assertSee('No hay citas que coincidan con los filtros.');

        $this->registrar($this->admin);
        $otro = $this->pacienteDe('ana');
        $this->insertarCita($otro, $this->medico, $this->cardiologia, $this->lunes, '09:00', '09:30', 'Cancelada');

        $this->actingAs($this->admin)->get(route('citas.index'))->assertSee('Prueba, Paciente01')->assertSee('Prueba, Ana');
        $this->actingAs($this->admin)->get(route('citas.index', ['estado' => 'Cancelada']))->assertSee('Prueba, Ana')->assertDontSee('Prueba, Paciente01');
        $this->actingAs($this->admin)->get(route('citas.index', ['q' => 'HC-' . $otro->id]))->assertSee('Prueba, Ana')->assertDontSee('Prueba, Paciente01');
    }

    public function test_acceso_por_perfil_y_permisos(): void
    {
        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $sinPermisos = $this->cuenta('auditor');
        DB::table('rol')->insert(['nombre' => 'Auditor']);
        DB::table('usuario_rol')->insert(['id_usuario' => $sinPermisos->id, 'id_rol' => DB::table('rol')->where('nombre', 'Auditor')->value('id_rol')]);

        $this->registrar($recepcion)->assertSessionHasNoErrors();
        $cita = Cita::first();
        $this->actingAs($recepcion)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'Motivo'])->assertSessionHas('status');

        foreach ([$this->paciente, $this->medico] as $usuario) {
            $this->actingAs($usuario)->get(route('citas.index'))->assertForbidden();
            $this->actingAs($usuario)->get(route('disponibilidad.index'))->assertForbidden();
            $this->registrar($usuario)->assertForbidden();
        }

        $this->actingAs($sinPermisos->fresh())->get(route('citas.index'))->assertForbidden();
        $this->actingAs($sinPermisos->fresh())->get(route('disponibilidad.index'))->assertForbidden();
    }

    public function test_las_vistas_no_contienen_javascript_y_usan_csrf(): void
    {
        $this->registrar($this->admin);
        $cita = Cita::first();

        foreach ([route('citas.index'), route('citas.show', $cita), route('disponibilidad.index', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes])] as $url) {
            $this->sinJavascript($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
        }

        $this->actingAs($this->admin)->get(route('citas.show', $cita))->assertSee('name="_method" value="PATCH"', false)->assertSee('name="_token"', false);
    }
}
