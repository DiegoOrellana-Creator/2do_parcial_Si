<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Bitácora: acceso exclusivo del administrador, consulta con filtros y registro automático.
 */
class BitacoraTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private Especialidad $cardiologia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->cardiologia = $this->especialidadActiva();
    }

    /** Filas de la bitácora creadas durante la prueba (acción y módulo). */
    private function eventos(): array
    {
        return Bitacora::orderBy('id_bitacora')->get()->map(fn ($b) => "{$b->accion} · {$b->modulo}")->all();
    }

    private function registro(array $datos): Bitacora
    {
        return Bitacora::create(['fecha_hora' => now(), 'accion' => 'Creación', 'modulo' => 'Pacientes', ...$datos]);
    }

    // --- Acceso ------------------------------------------------------------------

    public function test_solo_el_administrador_consulta_la_bitacora(): void
    {
        $this->get(route('bitacora.index'))->assertRedirect(route('login'));

        $this->actingAs($this->admin)->get(route('bitacora.index'))->assertOk()->assertSee('Bitácora');
        $porFicha = $this->cuenta('admin_ficha');
        DB::table('administrador')->insert(['id_administrador' => $porFicha->id]);
        $this->actingAs($porFicha)->get(route('bitacora.index'))->assertOk();

        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('recepcionista')->insert(['id_recepcionista' => $recepcion->id]);
        foreach ([$recepcion, $this->medicoCon('medico01', $this->cardiologia), $this->pacienteDe('paciente01'), $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get(route('bitacora.index'))->assertForbidden();
        }

        // Un administrador con la cuenta inactiva tampoco.
        DB::table('users')->where('id', $porFicha->id)->update(['estado' => User::ESTADO_INACTIVO]);
        $this->actingAs($porFicha->fresh())->get(route('bitacora.index'))->assertForbidden();
    }

    public function test_los_registros_no_se_modifican_ni_se_eliminan(): void
    {
        $registro = $this->registro(['detalle' => 'Original']);

        // No existen rutas de escritura.
        $this->actingAs($this->admin)->post('/bitacora')->assertStatus(405);
        $this->actingAs($this->admin)->delete('/bitacora/' . $registro->id_bitacora)->assertNotFound();

        try {
            $registro->update(['detalle' => 'Cambiado']);
            $this->fail('Se esperaba una excepción al modificar.');
        } catch (LogicException) {
        }
        try {
            $registro->delete();
            $this->fail('Se esperaba una excepción al eliminar.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('bitacora', ['id_bitacora' => $registro->id_bitacora, 'detalle' => 'Original']);
    }

    public function test_el_enlace_del_menu_solo_lo_ve_el_administrador(): void
    {
        $menu = fn (User $u) => preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $this->actingAs($u)->get(route('dashboard'))->getContent(), $m) ? $m[1] : '';

        $this->assertStringContainsString('href="' . route('bitacora.index') . '"', $menu($this->admin));

        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $this->assertStringNotContainsString('Bitácora', $menu($recepcion));
        $this->assertStringNotContainsString('Bitácora', $menu($this->medicoCon('medico01', $this->cardiologia)));
        $this->assertStringNotContainsString('Bitácora', $menu($this->pacienteDe('paciente01')));
    }

    // --- Pantalla ------------------------------------------------------------------

    public function test_muestra_filtra_busca_y_pagina_del_mas_reciente_al_mas_antiguo(): void
    {
        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $this->registro(['id_usuario' => $this->admin->id, 'fecha_hora' => '2026-10-01 08:00:00', 'accion' => 'Inicio de sesión', 'modulo' => 'Sesión', 'ip' => '10.0.0.1']);
        $this->registro(['id_usuario' => $recepcion->id, 'fecha_hora' => '2026-10-10 09:30:00', 'accion' => 'Creación', 'modulo' => 'Pacientes', 'id_registro' => 77, 'detalle' => 'Paciente «Ana Rojas» registrado.']);
        $this->registro(['id_usuario' => null, 'fecha_hora' => '2026-10-20 18:45:00', 'accion' => 'Eliminación', 'modulo' => 'Cuenta', 'detalle' => 'El usuario «x» eliminó su propia cuenta.']);

        $html = $this->actingAs($this->admin)->get(route('bitacora.index'))->assertOk()->getContent();
        preg_match_all('#<td data-label="Acción">(.*?)</td>#', $html, $acciones);
        $this->assertSame(['Eliminación', 'Creación', 'Inicio de sesión'], $acciones[1]);
        foreach (['20/10/2026', '18:45:00', 'Sin usuario', 'Recepcion Prueba', '#77', '10.0.0.1', 'Paciente «Ana Rojas» registrado.'] as $texto) {
            $this->assertStringContainsString(e($texto), $html);
        }
        $this->sinJavascript($html);

        $filtrar = fn (array $f) => $this->actingAs($this->admin)->get(route('bitacora.index', $f))->assertOk();
        $filtrar(['usuario' => $recepcion->id])->assertSee('Ana Rojas')->assertDontSee('10.0.0.1');
        $filtrar(['modulo' => 'Sesión'])->assertSee('10.0.0.1')->assertDontSee('Ana Rojas');
        $filtrar(['accion' => 'Eliminación'])->assertSee('eliminó su propia cuenta')->assertDontSee('Ana Rojas');
        $filtrar(['desde' => '2026-10-05', 'hasta' => '2026-10-15'])->assertSee('Ana Rojas')->assertDontSee('10.0.0.1')->assertDontSee('propia cuenta');
        $filtrar(['q' => 'ana rojas'])->assertSee('Ana Rojas')->assertDontSee('10.0.0.1');
        $filtrar(['q' => 'recepcion'])->assertSee('Ana Rojas');
        $filtrar(['q' => '77'])->assertSee('Ana Rojas')->assertDontSee('10.0.0.1');
        $filtrar(['q' => 'zzz'])->assertSee('No hay registros en la bitácora que coincidan con los filtros.');
        $filtrar(['desde' => '2026-10-15', 'hasta' => '2026-10-01'])->assertSee('La fecha «Desde» es posterior a «Hasta»');
    }

    public function test_pagina_de_veinte_en_veinte(): void
    {
        foreach (range(1, 25) as $n) {
            $this->registro(['fecha_hora' => now()->subMinutes(100 - $n), 'detalle' => sprintf('Evento %02d', $n)]);
        }

        $this->actingAs($this->admin)->get(route('bitacora.index'))
            ->assertSee('Mostrando 1–20 de 25 registros')->assertSee('Evento 25')->assertDontSee('Evento 05');
        $this->actingAs($this->admin)->get(route('bitacora.index', ['page' => 2]))
            ->assertSee('Mostrando 21–25 de 25 registros')->assertSee('Evento 05')->assertDontSee('Evento 25');
    }

    public function test_sin_registros_muestra_un_mensaje(): void
    {
        $this->actingAs($this->admin)->get(route('bitacora.index'))->assertOk()
            ->assertSee('No hay registros en la bitácora que coincidan con los filtros.');
    }

    // --- Registro automático -------------------------------------------------------

    public function test_registra_inicio_cierre_e_intentos_fallidos_de_sesion_sin_secretos(): void
    {
        $paciente = $this->pacienteDe('paciente01');

        $this->post(route('login'), ['email' => $paciente->email, 'password' => 'Clave-Equivocada-123']);
        $this->post(route('login'), ['email' => 'no.existe@ejemplo.test', 'password' => 'Otra-Clave-456']);
        $this->post(route('login'), ['email' => $paciente->email, 'password' => 'password'])->assertRedirect();
        $this->post(route('logout'));

        $this->assertSame([
            'Inicio de sesión fallido · Sesión', 'Inicio de sesión fallido · Sesión', 'Inicio de sesión · Sesión', 'Cierre de sesión · Sesión',
        ], $this->eventos());

        [$fallido, $desconocido, $inicio, $cierre] = Bitacora::orderBy('id_bitacora')->get();
        $this->assertSame([$paciente->id, $paciente->id], [(int) $fallido->id_usuario, (int) $fallido->id_registro]);
        $this->assertNull($desconocido->id_usuario);
        $this->assertSame($paciente->id, (int) $inicio->id_usuario);
        $this->assertSame($paciente->id, (int) $cierre->id_usuario);
        $this->assertSame('127.0.0.1', $inicio->ip);
        $this->assertSame(now()->format('Y-m-d H'), substr($inicio->fecha_hora, 0, 13), 'Hora local de la clínica');
        $this->sinSecretos();
    }

    public function test_registra_pacientes_especialidades_y_horarios_una_vez_por_operacion(): void
    {
        $datosPaciente = [
            'nombre' => 'Ana', 'apellido' => 'Rojas Vaca', 'email' => 'ana.rojas@ejemplo.test', 'username' => 'ana_rojas',
            'telefono' => '70000000', 'nro_ficha' => 'F-0001',
        ];
        $this->actingAs($this->admin)->post(route('pacientes.store'), $datosPaciente)->assertSessionHasNoErrors();
        $idPaciente = User::where('username', 'ana_rojas')->value('id');
        $this->actingAs($this->admin)->put(route('pacientes.update', $idPaciente), [...$datosPaciente, 'telefono' => '71111111'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('especialidades.store'), ['nombre' => 'Pediatría', 'estado' => 'Activo'])->assertSessionHasNoErrors();
        $idEsp = Especialidad::where('nombre', 'Pediatría')->value('id_especialidad');
        $this->actingAs($this->admin)->put(route('especialidades.update', $idEsp), ['nombre' => 'Pediatría', 'estado' => 'Inactivo'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete(route('especialidades.destroy', $idEsp))->assertSessionHas('status');

        $medico = $this->medicoCon('medico01', $this->cardiologia, null);
        $horario = ['id_medico' => $medico->id, 'dia_semana' => 6, 'hora_inicio' => '08:00', 'hora_final' => '12:00', 'duracion_cita' => 30];
        $this->actingAs($this->admin)->post(route('horarios.store'), $horario)->assertSessionHasNoErrors();
        $idHorario = DB::table('horario_atencion')->value('id_horario');
        $this->actingAs($this->admin)->put(route('horarios.update', $idHorario), [...$horario, 'hora_final' => '11:00'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete(route('horarios.destroy', $idHorario))->assertSessionHas('status');

        // Operaciones rechazadas por validación: no dejan registro.
        $this->actingAs($this->admin)->post(route('pacientes.store'), [...$datosPaciente, 'nombre' => ''])->assertSessionHasErrors('nombre');
        $this->actingAs($this->admin)->post(route('horarios.store'), [...$horario, 'hora_final' => '07:00'])->assertSessionHasErrors('hora_final');

        $this->assertSame([
            'Creación · Pacientes', 'Modificación · Pacientes',
            'Creación · Especialidades', 'Modificación · Especialidades', 'Cambio de estado · Especialidades', 'Eliminación · Especialidades',
            'Creación · Horarios de atención', 'Modificación · Horarios de atención', 'Eliminación · Horarios de atención',
        ], $this->eventos());

        $this->assertDatabaseHas('bitacora', ['accion' => 'Modificación', 'modulo' => 'Pacientes', 'id_registro' => $idPaciente, 'id_usuario' => $this->admin->id]);
        $this->assertStringContainsString('telefono', Bitacora::where('accion', 'Modificación')->where('modulo', 'Pacientes')->value('detalle'));
        $this->assertSame('Especialidad «Pediatría»: Activo → Inactivo.', Bitacora::where('accion', 'Cambio de estado')->value('detalle'));
        $this->assertSame('Horario modificado: Sábado 08:00–12:00, citas de 30 min, Medico01 Prueba → Sábado 08:00–11:00, citas de 30 min, Medico01 Prueba.',
            Bitacora::where('accion', 'Modificación')->where('modulo', 'Horarios de atención')->value('detalle'));
        $this->sinSecretos();
    }

    public function test_registra_medicos_sin_su_contrasena_y_no_registra_operaciones_revertidas(): void
    {
        $datos = [
            'nombre' => 'Luis', 'apellido' => 'Herrera', 'email' => 'luis.herrera@ejemplo.test', 'username' => 'lherrera', 'estado' => 'Activo',
            'password' => 'Clave-Medico-2026', 'password_confirmation' => 'Clave-Medico-2026', 'especialidades' => [$this->cardiologia->id_especialidad],
        ];

        // Si falla la creación del perfil, la transacción se revierte y no queda registro.
        Medico::creating(fn () => throw new \RuntimeException('Fallo simulado.'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->post(route('medicos.store'), $datos);
        } catch (\RuntimeException) {
        }
        $this->withExceptionHandling();
        Medico::flushEventListeners();
        $this->assertSame([], $this->eventos());

        $this->actingAs($this->admin)->post(route('medicos.store'), $datos)->assertSessionHasNoErrors();
        $id = User::where('username', 'lherrera')->value('id');
        $this->actingAs($this->admin)->put(route('medicos.update', $id), [...$datos, 'estado' => 'Inactivo', 'telefono' => '70000000'])->assertSessionHasNoErrors();

        $this->assertSame(['Creación · Médicos', 'Modificación · Médicos', 'Cambio de estado · Médicos'], $this->eventos());
        $this->assertSame('Cuenta del médico «Luis Herrera»: Activo → Inactivo.', Bitacora::where('accion', 'Cambio de estado')->value('detalle'));
        $this->sinSecretos(['Clave-Medico-2026']);
    }

    public function test_registra_citas_del_personal_y_del_paciente_y_su_cancelacion(): void
    {
        $medico = $this->medicoCon('medico01', $this->cardiologia);
        $paciente = $this->pacienteDe('paciente01');
        $lunes = $this->proximoLunes()->toDateString();
        $base = ['id_especialidad' => $this->cardiologia->id_especialidad, 'fecha' => $lunes];

        $this->actingAs($this->admin)->post(route('citas.store'), $base + ['id_paciente' => $paciente->id, 'franja' => $medico->id . '|08:00'])->assertSessionHasNoErrors();
        $otroMedico = $this->medicoCon('medico02', $this->cardiologia);
        $this->actingAs($paciente)->post(route('mis-citas.store'), $base + ['franja' => $otroMedico->id . '|09:00'])->assertSessionHasNoErrors();
        // Franja ocupada: rechazada dentro de la transacción, sin registro.
        $this->actingAs($paciente)->post(route('mis-citas.store'), $base + ['franja' => $medico->id . '|08:00'])->assertSessionHasErrors('franja');

        $cita = Cita::orderBy('id_cita')->first();
        $this->actingAs($this->admin)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'El paciente avisó.'])->assertSessionHas('status');
        // Segundo intento de cancelar: ya no es cancelable, no se duplica el evento.
        $this->actingAs($this->admin)->patch(route('citas.cancelar', $cita), ['motivo_cancelacion' => 'Otra vez.'])->assertSessionHas('error');

        $this->assertSame(['Creación · Citas', 'Creación · Citas', 'Cambio de estado · Citas'], $this->eventos());
        [$personal, $propia, $cancelacion] = Bitacora::orderBy('id_bitacora')->get();
        $this->assertStringContainsString('registrada por el personal', $personal->detalle);
        $this->assertSame($this->admin->id, (int) $personal->id_usuario);
        $this->assertStringContainsString('solicitada por el propio paciente', $propia->detalle);
        $this->assertSame($paciente->id, (int) $propia->id_usuario);
        $this->assertSame("Cita {$cita->codigo}: Programada → Cancelada. Motivo: El paciente avisó.", $cancelacion->detalle);
        $this->assertSame((int) $cita->id_cita, (int) $cancelacion->id_registro);
    }

    public function test_registra_cambios_de_la_propia_cuenta_y_no_impide_eliminarla(): void
    {
        $usuario = $this->cuenta('sin_vinculos');

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password']);
        $this->actingAs($usuario)->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
        ])->assertSessionHasNoErrors();

        // Sus propios eventos de sesión y cuenta no bloquean la eliminación de la cuenta.
        $this->actingAs($usuario->fresh())->delete(route('profile.destroy'), ['password' => 'Nueva-Clave-2026'])->assertRedirect('/');
        $this->assertDatabaseMissing('users', ['id' => $usuario->id]);

        $this->assertSame(['Inicio de sesión · Sesión', 'Cambio de contraseña · Cuenta', 'Eliminación · Cuenta'], $this->eventos());
        $eliminacion = Bitacora::where('accion', 'Eliminación')->sole();
        $this->assertNull($eliminacion->id_usuario);
        $this->assertSame('El usuario «sin_vinculos» eliminó su propia cuenta.', $eliminacion->detalle);
        // Los registros anteriores quedan sin autor (ON DELETE SET NULL), no se borran.
        $this->assertSame(3, Bitacora::count());
        $this->sinSecretos(['Nueva-Clave-2026']);
    }

    /** Ningún registro contiene contraseñas, hashes ni tokens. */
    private function sinSecretos(array $claves = []): void
    {
        $texto = Bitacora::all()->map(fn ($b) => implode(' ', [$b->accion, $b->modulo, $b->detalle, $b->ip]))->implode("\n");

        foreach (['password', '$2y$', 'remember_token', 'Clave-Equivocada-123', 'Otra-Clave-456', 'no.existe@ejemplo.test', ...$claves] as $secreto) {
            $this->assertStringNotContainsString($secreto, $texto);
        }
        foreach (User::all() as $usuario) {
            $this->assertStringNotContainsString($usuario->getAuthPassword(), $texto);
        }
    }
}
