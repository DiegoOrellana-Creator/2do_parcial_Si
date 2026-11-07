<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * El paciente cancela o reprograma por su cuenta solo con 24 horas de anticipación; la recepción
 * puede hacerlo hasta la hora de la cita.
 */
class PlazoPacienteTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $recepcion;

    private User $medico;

    private User $paciente;

    private Especialidad $cardiologia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        Mail::fake();
        // Lunes 1 de marzo de 2027, 10:00.
        $this->travelTo(Carbon::parse('2027-03-01 10:00'));

        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $this->recepcion->id]);
        $this->cardiologia = $this->especialidadActiva();
        $this->medico = $this->medicoCon('medico01', $this->cardiologia, [2, '08:00', '18:00', 30]);
        $this->paciente = $this->pacienteDe('paciente01');
    }

    private function cita(string $fecha, string $inicio, string $fin): Cita
    {
        return Cita::findOrFail($this->insertarCita($this->paciente, $this->medico, $this->cardiologia, $fecha, $inicio, $fin, Cita::CONFIRMADA));
    }

    public function test_con_menos_de_24_horas_el_paciente_no_cancela_ni_reprograma(): void
    {
        // Martes 09:00: faltan 23 horas.
        $cita = $this->cita('2027-03-02', '09:00', '09:30');
        $aviso = 'Faltan menos de 24 horas para tu cita';

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $cita))->assertOk()
            ->assertDontSee('Cancelar cita')->assertDontSee(route('mis-citas.reprogramar', $cita))
            ->assertSee('para cancelarla o reprogramarla, comunícate con la clínica.');

        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $cita), ['motivo_cancelacion' => 'No puedo'])
            ->assertRedirect(route('mis-citas.show', $cita))
            ->assertSessionHas('error', 'Faltan menos de 24 horas para tu cita: ya no puedes cancelarla desde aquí. Comunícate con la clínica.');
        $this->actingAs($this->paciente)->get(route('mis-citas.reprogramar', $cita))
            ->assertRedirect(route('mis-citas.show', $cita))->assertSessionHas('error', fn ($m) => str_starts_with($m, $aviso) && str_contains($m, 'reprogramarla'));
        $this->actingAs($this->paciente)->patch(route('mis-citas.reprogramar.guardar', $cita), [
            'fecha' => '2027-03-02', 'franja' => $this->medico->id . '|15:00', 'motivo_reprogramacion' => 'Otra hora',
        ])->assertSessionHas('error', fn ($m) => str_starts_with($m, $aviso));

        $cita->refresh();
        $this->assertSame([Cita::CONFIRMADA, '09:00:00'], [$cita->estado, $cita->hora_inicio]);

        // La recepción sí puede cancelarla hasta la hora de la cita.
        $this->actingAs($this->recepcion)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'Llamó el paciente'])->assertSessionHas('status');
        $this->assertSame(Cita::CANCELADA, $cita->fresh()->estado);
    }

    public function test_con_24_horas_o_mas_el_paciente_si_puede(): void
    {
        // Martes 10:00 (justo 24 horas) y 11:00 (25 horas).
        $justa = $this->cita('2027-03-02', '10:00', '10:30');
        $holgada = $this->cita('2027-03-02', '11:00', '11:30');

        $this->actingAs($this->paciente)->get(route('mis-citas.show', $justa))->assertSee('Cancelar cita')->assertSee(route('mis-citas.reprogramar', $justa), false);
        $this->actingAs($this->paciente)->get(route('mis-citas.reprogramar', $holgada))->assertOk();

        $this->actingAs($this->paciente)->patch(route('mis-citas.cancelar', $justa), ['motivo_cancelacion' => 'Viaje'])->assertSessionHas('status');
        $this->assertSame(Cita::CANCELADA, $justa->fresh()->estado);
    }
}
