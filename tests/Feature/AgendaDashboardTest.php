<?php

namespace Tests\Feature;

use App\Models\Especialidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Agenda médica (personal y médico) y dashboard con datos reales.
 */
class AgendaDashboardTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private Especialidad $cardiologia;

    private User $medico;

    private User $otroMedico;

    private User $paciente;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia);
        $this->otroMedico = $this->medicoCon('medico02', $this->cardiologia);
        $this->paciente = $this->pacienteDe('paciente01');
        $this->lunes = $this->proximoLunes()->toDateString();
    }

    // --- Agenda --------------------------------------------------------------

    public function test_el_personal_consulta_la_agenda_de_cualquier_medico(): void
    {
        $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00');
        $this->insertarCita($this->pacienteDe('ana'), $this->medico, $this->cardiologia, $this->lunes, '09:00', '09:30', 'Cancelada');

        $this->actingAs($this->admin)->get(route('agenda.index', ['medico' => $this->medico->id, 'fecha' => $this->lunes]))
            ->assertOk()
            ->assertSee('Agenda médica')->assertSee('Medico01 Prueba')
            ->assertSee('08:30–09:00')->assertSee('Paciente01 Prueba')
            ->assertSee('2 citas, incluidas las canceladas')
            ->assertSee('Día siguiente');
    }

    public function test_el_medico_solo_ve_su_propia_agenda(): void
    {
        $this->insertarCita($this->paciente, $this->otroMedico, $this->cardiologia, $this->lunes, '08:00', '08:30');

        $this->actingAs($this->medico)->get(route('agenda.index', ['fecha' => $this->lunes]))
            ->assertOk()->assertSee('Mi agenda')->assertSee('Medico01 Prueba')->assertDontSee('Paciente01 Prueba');

        $this->actingAs($this->medico)->get(route('agenda.index', ['medico' => $this->otroMedico->id]))->assertForbidden();
        $this->actingAs($this->medico)->get(route('agenda.index', ['medico' => $this->medico->id]))->assertOk();
    }

    public function test_pacientes_y_personal_sin_permiso_no_ven_la_agenda(): void
    {
        $this->get(route('agenda.index'))->assertRedirect(route('login'));

        $this->actingAs($this->paciente)->get(route('agenda.index'))->assertForbidden();
        $this->actingAs($this->paciente)->get(route('agenda.index', ['medico' => $this->medico->id]))->assertForbidden();
        $this->actingAs($this->cuenta('nadie'))->get(route('agenda.index'))->assertForbidden();

        $auditor = $this->cuenta('auditor');
        DB::table('rol')->insert(['nombre' => 'Auditor']);
        DB::table('usuario_rol')->insert(['id_usuario' => $auditor->id, 'id_rol' => DB::table('rol')->where('nombre', 'Auditor')->value('id_rol')]);
        $this->actingAs($auditor->fresh())->get(route('agenda.index'))->assertForbidden();
    }

    public function test_el_menu_del_medico_enlaza_su_agenda(): void
    {
        $html = $this->actingAs($this->medico)->get(route('agenda.index'))->getContent();
        preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $menu);

        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('agenda.index'), '#') . '"\s*aria-current="page"#', $menu[1]);
        $this->sinJavascript($html);
    }

    // --- Dashboard -------------------------------------------------------------

    public function test_el_dashboard_vacio_no_inventa_datos(): void
    {
        DB::table('horario_atencion')->delete();

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Datos de demostración')->assertDontSee('María Fernanda Rojas')
            ->assertSee('No hay citas próximas.')
            ->assertSee('Ningún médico activo tiene horario de atención hoy.')
            ->assertSee('No hay citas registradas este mes.');
    }

    public function test_el_dashboard_muestra_datos_reales(): void
    {
        $hoy = now()->toDateString();
        $this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $this->lunes, '08:00', '08:30');
        $this->insertarCita($this->pacienteDe('ana'), $this->medico, $this->cardiologia, $this->lunes, '08:30', '09:00', 'Cancelada');
        DB::table('horario_atencion')->insert(['id_medico' => $this->otroMedico->id, 'dia_semana' => now()->dayOfWeekIso, 'hora_inicio' => '14:00:00', 'hora_final' => '18:00:00', 'duracion_cita' => 30]);

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        // Tarjetas: 0 citas hoy, 1 pendiente, 2 pacientes, 2 médicos activos.
        $this->assertMatchesRegularExpression('#Citas pendientes</p>\s*<p class="stat-card__value">1</p>#', $html);
        $this->assertMatchesRegularExpression('#Pacientes registrados</p>\s*<p class="stat-card__value">2</p>#', $html);
        $this->assertMatchesRegularExpression('#Médicos activos</p>\s*<p class="stat-card__value">2</p>#', $html);
        $this->assertStringContainsString('Paciente01 Prueba', $html);
        $this->assertStringContainsString('Medico02 Prueba', $html);                 // atiende hoy
        $this->assertStringContainsString('14:00–18:00', $html);
        $this->assertStringContainsString('href="' . route('citas.create') . '"', $html);
        $this->sinJavascript($html);
    }
}
