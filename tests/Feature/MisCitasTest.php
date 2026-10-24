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
 * Funciones del paciente: disponibilidad médica, solicitar cita y mis citas.
 * El médico de prueba atiende los lunes de 08:00 a 10:00 en franjas de 30 minutos.
 */
class MisCitasTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private Especialidad $cardiologia;

    private User $medico;

    private User $paciente;

    private User $otroPaciente;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->otroPaciente = $this->pacienteDe('ajeno');
        $this->lunes = $this->proximoLunes()->toDateString();
    }

    private function solicitud(array $cambios = []): array
    {
        return array_merge([
            'id_especialidad' => $this->cardiologia->id_especialidad,
            'fecha' => $this->lunes,
            'franja' => $this->medico->id . '|09:00',
            'motivo' => 'Control anual',
        ], $cambios);
    }

    // --- Acceso ----------------------------------------------------------------

    public function test_solo_los_pacientes_usan_estas_funciones(): void
    {
        $rutas = [route('mis-citas.index'), route('mis-citas.create'), route('mis-citas.disponibilidad')];

        foreach ($rutas as $ruta) {
            $this->get($ruta)->assertRedirect(route('login'));
        }
        $this->post(route('mis-citas.store'), $this->solicitud())->assertRedirect(route('login'));

        foreach ($rutas as $ruta) {
            $this->actingAs($this->paciente)->get($ruta)->assertOk();
        }

        foreach ([$this->cuenta('admin', 'Administrador'), $this->cuenta('recepcion', 'Recepcionista'), $this->medico, $this->cuenta('nadie')] as $otro) {
            foreach ($rutas as $ruta) {
                $this->actingAs($otro)->get($ruta)->assertForbidden();
            }
            $this->actingAs($otro)->post(route('mis-citas.store'), $this->solicitud())->assertForbidden();
        }

        // Y el paciente sigue sin entrar a la gestión del personal.
        $this->actingAs($this->paciente)->get(route('citas.index'))->assertForbidden();
        $this->actingAs($this->paciente)->post(route('citas.store'), $this->solicitud(['id_paciente' => $this->paciente->id]))->assertForbidden();
        $this->assertSame(0, Cita::count());
    }

    // --- Disponibilidad --------------------------------------------------------

    public function test_la_disponibilidad_solo_muestra_horarios_realmente_libres(): void
    {
        $this->insertarCita($this->otroPaciente, $this->medico, $this->cardiologia, $this->lunes, '08:00', '08:30');
        $this->insertarCita($this->otroPaciente, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00', Cita::NO_ASISTIO);
        $this->insertarCita($this->otroPaciente, $this->medico, $this->cardiologia, $this->lunes, '09:00', '09:30', Cita::CANCELADA);
        DB::table('bloqueo_agenda')->insert(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:30:00', 'hora_final' => '10:00:00', 'motivo' => 'Congreso']);
        $this->medicoCon('inactivo', $this->cardiologia, [1, '08:00', '10:00', 30], User::ESTADO_INACTIVO);

        $html = $this->actingAs($this->paciente)
            ->get(route('mis-citas.disponibilidad', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes]))
            ->assertOk()->getContent();

        // Ocupada (Programada), ocupada (No asistió) y bloqueada no se ofrecen; la cancelada vuelve a estar libre.
        preg_match_all('#<a class="slot slot--libre"[^>]*>(\d{2}:\d{2})</a>#', $html, $libres);
        $this->assertSame(['09:00'], $libres[1]);
        $this->assertStringContainsString(e(route('mis-citas.create', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes, 'medico' => $this->medico->id, 'franja' => $this->medico->id . '|09:00'])), $html);

        // Sin datos de otros pacientes ni médicos inactivos.
        $this->assertStringNotContainsString('Ajeno', $html);
        $this->assertStringNotContainsString('Congreso', $html);
        $this->assertStringNotContainsString('Inactivo Prueba', $html);
        $this->sinJavascript($html);
    }

    public function test_la_disponibilidad_valida_la_consulta(): void
    {
        $inactiva = $this->especialidadActiva('Homeopatía', 'Inactivo');

        $this->actingAs($this->paciente)->get(route('mis-citas.disponibilidad', ['especialidad' => $inactiva->id_especialidad, 'fecha' => $this->lunes]))
            ->assertOk()->assertSee('La especialidad seleccionada no existe o no está activa.');
        $this->actingAs($this->paciente)->get(route('mis-citas.disponibilidad', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => now()->subDay()->toDateString()]))
            ->assertOk()->assertSee('La fecha no puede ser anterior a hoy.');
    }

    // --- Solicitar cita --------------------------------------------------------

    public function test_el_paciente_solicita_una_cita_a_su_nombre(): void
    {
        $this->actingAs($this->paciente)->get(route('mis-citas.create', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes]))
            ->assertOk()->assertSee('Medico01 Prueba')->assertSee('09:00–09:30')->assertDontSee('name="id_paciente"', false);

        $respuesta = $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud());

        $cita = Cita::sole();
        $respuesta->assertRedirect(route('mis-citas.show', $cita))->assertSessionHas('status', "Tu cita {$cita->codigo} quedó registrada en estado «Programada».");
        $this->assertSame($this->paciente->id, (int) $cita->id_paciente);
        $this->assertSame($this->medico->id, (int) $cita->id_medico);
        $this->assertSame([$this->lunes, '09:00:00', '09:30:00', Cita::PROGRAMADA, 'Control anual'],
            [substr($cita->fecha_cita, 0, 10), $cita->hora_inicio, $cita->hora_fin, $cita->estado, $cita->motivo]);
        $this->assertDatabaseHas('historial_estado_cita', ['id_cita' => $cita->id_cita, 'estado_nuevo' => Cita::PROGRAMADA, 'registrado_por' => $this->paciente->id]);

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $cita))
            ->assertOk()->assertSee($cita->codigo)->assertSee('Programada')->assertSee('Medico01 Prueba')->assertSee('Cardiología');
    }

    public function test_no_puede_registrar_citas_a_nombre_de_otro_paciente(): void
    {
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['id_paciente' => $this->otroPaciente->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->paciente->id, (int) Cita::sole()->id_paciente);
        $this->assertSame(0, Cita::where('id_paciente', $this->otroPaciente->id)->count());
    }

    public function test_rechaza_horarios_no_libres_conflictos_y_duplicados(): void
    {
        $this->insertarCita($this->otroPaciente, $this->medico, $this->cardiologia, $this->lunes, '08:00', '08:30');
        $this->insertarCita($this->otroPaciente, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00', Cita::NO_ASISTIO);
        DB::table('bloqueo_agenda')->insert(['id_medico' => $this->medico->id, 'fecha' => $this->lunes, 'hora_inicio' => '09:30:00', 'hora_final' => '10:00:00']);

        foreach (['08:00', '08:30', '09:30', '09:15', '11:00'] as $hora) {
            $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['franja' => $this->medico->id . '|' . $hora]))
                ->assertSessionHasErrors('franja');
        }
        $this->assertSame(0, Cita::where('id_paciente', $this->paciente->id)->count());

        // Una cita válida; después, otra con el mismo médico ese día (duplicado) o a la misma hora con otro médico (conflicto).
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud())->assertSessionHasNoErrors();
        DB::table('citas')->where('estado', Cita::PROGRAMADA)->where('id_paciente', $this->otroPaciente->id)->update(['estado' => Cita::CANCELADA]);
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['franja' => $this->medico->id . '|08:00']))
            ->assertSessionHasErrors(['franja' => 'Ya tienes una cita pendiente con ese médico ese día.']);

        $otroMedico = $this->medicoCon('medico02', $this->cardiologia);
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['franja' => $otroMedico->id . '|09:00']))
            ->assertSessionHasErrors(['franja' => 'El paciente ya tiene otra cita a esa hora.']);

        $this->assertSame(1, Cita::where('id_paciente', $this->paciente->id)->count());
    }

    public function test_rechaza_datos_invalidos(): void
    {
        $inactiva = $this->especialidadActiva('Homeopatía', 'Inactivo');
        $otra = $this->especialidadActiva('Pediatría');

        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['id_especialidad' => $inactiva->id_especialidad]))
            ->assertSessionHasErrors(['id_especialidad' => 'La especialidad no está activa.']);
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['id_especialidad' => $otra->id_especialidad]))
            ->assertSessionHasErrors(['franja' => 'El médico seleccionado no ejerce esa especialidad.']);
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['fecha' => now()->subWeek()->startOfWeek()->toDateString()]))
            ->assertSessionHasErrors('fecha');
        $this->actingAs($this->paciente)->post(route('mis-citas.store'), $this->solicitud(['franja' => 'x']))
            ->assertSessionHasErrors('franja');

        $this->assertSame(0, Cita::count());
    }

    public function test_un_paciente_bloqueado_no_puede_solicitar(): void
    {
        $bloqueado = $this->pacienteDe('bloqueado', bloqueado: true);

        $this->actingAs($bloqueado)->get(route('mis-citas.create', ['especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $this->lunes]))
            ->assertOk()->assertSee('Tu cuenta tiene la solicitud de citas bloqueada.')->assertDontSee('name="franja"', false);
        $this->actingAs($bloqueado)->post(route('mis-citas.store'), $this->solicitud())
            ->assertSessionHasErrors(['id_paciente' => 'El paciente está bloqueado y no puede registrar citas.']);

        $this->assertSame(0, Cita::count());
    }

    // --- Mis citas -------------------------------------------------------------

    public function test_mis_citas_solo_muestra_las_propias(): void
    {
        $pasada = now()->subWeek()->startOfWeek()->toDateString();
        $propiaFutura = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '08:00', '08:30', Cita::CONFIRMADA);
        $propiaPasada = $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $pasada, '08:00', '08:30', Cita::NO_ASISTIO);
        $ajena = $this->insertarCita($this->otroPaciente, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00');

        $html = $this->actingAs($this->paciente)->get(route('mis-citas.index'))->assertOk()->getContent();

        $this->assertStringContainsString(Cita::find($propiaFutura)->codigo, $html);
        $this->assertStringContainsString(Cita::find($propiaPasada)->codigo, $html);
        $this->assertStringNotContainsString(Cita::find($ajena)->codigo, $html);
        $this->assertStringContainsString('Confirmada', $html);
        $this->assertStringContainsString('No asistió', $html);
        $this->assertStringContainsString('Medico01 Prueba', $html);
        $this->assertStringContainsString('Cardiología', $html);
        $this->sinJavascript($html);

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $propiaFutura))->assertOk();
        $this->actingAs($this->paciente)->get(route('mis-citas.show', $ajena))->assertNotFound();
        $this->actingAs($this->paciente)->get(route('citas.show', $ajena))->assertForbidden();
        $this->actingAs($this->paciente)->patch(route('citas.cancelar', $ajena), ['motivo_cancelacion' => 'x'])->assertForbidden();
        $this->assertSame(Cita::PROGRAMADA, Cita::find($ajena)->estado);
    }

    // --- Menú --------------------------------------------------------------------

    public function test_el_menu_del_paciente_enlaza_sus_funciones(): void
    {
        $paginas = [
            'mis-citas.disponibilidad' => 'Disponibilidad médica',
            'mis-citas.create' => 'Solicitar cita',
            'mis-citas.index' => 'Mis citas',
        ];

        foreach ($paginas as $ruta => $etiqueta) {
            $html = $this->actingAs($this->paciente)->get(route($ruta))->assertOk()->getContent();
            preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $menu);

            $this->assertStringNotContainsString('Pronto', $menu[1]);
            // Solo la opción de la página actual queda marcada.
            preg_match_all('#aria-current="page"\s+class="nav-link is-active">.*?<span class="nav-link__label">(.*?)</span>#s', $menu[1], $activos);
            $this->assertSame([$etiqueta], $activos[1], $ruta);
        }
    }
}
