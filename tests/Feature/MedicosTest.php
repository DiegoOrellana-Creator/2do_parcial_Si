<?php

namespace Tests\Feature;

use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\User;
use Database\Seeders\PermisosEspecialidadesSeeder;
use Database\Seeders\PermisosMedicosSeeder;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Especialidad $cardiologia;

    private Especialidad $pediatria;

    private Especialidad $inactiva;

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class]);
        $this->admin = $this->usuario('admin', 'Administrador');
        $this->cardiologia = Especialidad::create(['nombre' => 'Cardiología', 'estado' => 'Activo']);
        $this->pediatria = Especialidad::create(['nombre' => 'Pediatría', 'estado' => 'Activo']);
        $this->inactiva = Especialidad::create(['nombre' => 'Homeopatía', 'estado' => 'Inactivo']);
    }

    private function usuario(string $username, ?string $rol = null, array $datos = []): User
    {
        $usuario = User::create([
            'nombre' => 'Usuario', 'apellido' => ucfirst($username), 'email' => "{$username}@ejemplo.test",
            'username' => $username, 'password' => 'password', 'estado' => 'Activo', ...$datos,
        ]);

        if ($rol) {
            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol')]);
        }

        return $usuario;
    }

    private function medico(string $username, array $especialidades = [], array $datosUsuario = [], array $datosMedico = []): Medico
    {
        $usuario = $this->usuario($username, null, $datosUsuario);
        $medico = Medico::create(['id_medico' => $usuario->id, 'matricula' => 'MAT-' . $usuario->id, ...$datosMedico]);
        $medico->especialidades()->attach(array_map(fn (Especialidad $e) => $e->id_especialidad, $especialidades));

        return $medico;
    }

    private function cita(Medico $medico, Especialidad $especialidad, string $fecha, string $estado = 'Programada'): void
    {
        static $n = 0;
        $paciente = $this->usuario('paciente' . (++$n));
        DB::table('paciente')->insert(['id_paciente' => $paciente->id, 'inasistencia' => 0, 'bloqueado' => false]);
        DB::table('citas')->insert([
            'codigo' => "C-{$n}", 'id_paciente' => $paciente->id, 'id_medico' => $medico->id_medico, 'id_especialidad' => $especialidad->id_especialidad,
            'fecha_cita' => $fecha, 'hora_inicio' => '09:00', 'hora_fin' => '09:30', 'estado' => $estado,
        ]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Luis', 'apellido' => 'Herrera', 'ci' => '4821563', 'fecha_nacimiento' => '1975-03-14', 'sexo' => 'Masculino',
            'telefono' => '70012345', 'direccion' => 'Av. Ejemplo 1', 'email' => 'luis.herrera@ejemplo.test', 'username' => 'lherrera',
            'estado' => 'Activo', 'matricula' => 'MP-2024/015', 'codigo' => 'MED-015',
            'password' => 'Clave-Medico-2026', 'password_confirmation' => 'Clave-Medico-2026',
            'especialidades' => [$this->cardiologia->id_especialidad, $this->pediatria->id_especialidad],
        ], $cambios);
    }

    // --- Acceso -------------------------------------------------------------

    public function test_acceso_por_permisos(): void
    {
        $medico = $this->medico('doctora', [$this->cardiologia]);
        $sinRol = $this->usuario('sin_rol');
        $recepcion = $this->usuario('recepcion', 'Recepcionista');

        $this->get(route('medicos.index'))->assertRedirect(route('login'));
        $this->actingAs($sinRol)->get(route('medicos.index'))->assertForbidden();
        $this->actingAs($sinRol)->post(route('medicos.store'), $this->datos())->assertForbidden();

        $this->actingAs($recepcion)->get(route('medicos.index'))->assertOk()->assertDontSee('Registrar médico');
        $this->actingAs($recepcion)->get(route('medicos.show', $medico))->assertOk();
        $this->actingAs($recepcion)->get(route('medicos.create'))->assertForbidden();
        $this->actingAs($recepcion)->get(route('medicos.edit', $medico))->assertForbidden();
        $this->actingAs($recepcion)->delete(route('medicos.destroy', $medico))->assertForbidden();
    }

    // --- Registro y especialidades -----------------------------------------

    public function test_registra_un_medico_con_varias_especialidades(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Médico registrado correctamente.');

        $usuario = User::firstWhere('username', 'lherrera');
        $this->assertSame('Activo', $usuario->estado);
        $this->assertNotSame('Clave-Medico-2026', $usuario->password);
        $this->assertTrue(Hash::check('Clave-Medico-2026', $usuario->password));
        $this->assertDatabaseHas('medico', ['id_medico' => $usuario->id, 'matricula' => 'MP-2024/015', 'codigo' => 'MED-015']);
        $this->assertSame(
            [$this->cardiologia->id_especialidad, $this->pediatria->id_especialidad],
            DB::table('especialidad_medico')->where('id_medico', $usuario->id)->orderBy('id_especialidad')->pluck('id_especialidad')->map(fn ($v) => (int) $v)->all()
        );

        // Rol «Médico» asignado (se crea si no existía) sin convertirlo en personal administrativo.
        $idRol = DB::table('rol')->where('nombre', 'Médico')->value('id_rol');
        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $usuario->id, 'id_rol' => $idRol, 'estado' => 'Activo']);
        $this->assertSame(User::PERFIL_MEDICO, $usuario->fresh()->perfil());
    }

    public function test_el_medico_registrado_inicia_sesion_con_sus_credenciales(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos())->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'luis.herrera@ejemplo.test', 'password' => 'otra-clave'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login'), ['email' => 'luis.herrera@ejemplo.test', 'password' => 'Clave-Medico-2026'])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::firstWhere('username', 'lherrera'));
        $this->get(route('agenda.index'))->assertOk()->assertSee('Mi agenda');
    }

    public function test_reutiliza_el_rol_medico_existente(): void
    {
        DB::table('rol')->insert(['nombre' => 'Médico']);
        $idRol = DB::table('rol')->where('nombre', 'Médico')->value('id_rol');

        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos([
            'email' => 'otra@ejemplo.test', 'username' => 'otra', 'ci' => null, 'matricula' => null, 'codigo' => null,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('rol')->where('nombre', 'Médico')->count());
        $this->assertSame(2, DB::table('usuario_rol')->where('id_rol', $idRol)->count());
    }

    public function test_exige_contrasena_confirmada_al_registrar(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['password' => '', 'password_confirmation' => '']))
            ->assertSessionHasErrors(['password' => 'El campo contraseña es obligatorio.']);
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['password' => 'corta', 'password_confirmation' => 'corta']))
            ->assertSessionHasErrors(['password' => 'El campo contraseña debe tener al menos 8 caracteres.']);
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['password_confirmation' => 'distinta-2026']))
            ->assertSessionHasErrors(['password' => 'La confirmación de la contraseña no coincide.']);

        $this->assertDatabaseMissing('users', ['username' => 'lherrera']);
    }

    public function test_si_falla_el_perfil_no_queda_una_cuenta_incompleta(): void
    {
        Medico::creating(fn () => throw new \RuntimeException('Fallo simulado al crear el perfil.'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos());
            $this->fail('Se esperaba el fallo simulado.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo simulado al crear el perfil.', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['username' => 'lherrera']);
        $this->assertSame(0, DB::table('usuario_rol')->whereNotIn('id_usuario', [$this->admin->id])->count());
    }

    public function test_editar_no_pide_ni_cambia_la_contrasena(): void
    {
        $medico = $this->medico('doctora', [$this->cardiologia]);
        $hash = $medico->usuario->password;

        $this->actingAs($this->admin)->get(route('medicos.edit', $medico))
            ->assertOk()->assertDontSee('name="password"', false)->assertDontSee('name="password_confirmation"', false);

        $this->actingAs($this->admin)->put(route('medicos.update', $medico), $this->datos([
            'email' => 'doctora@ejemplo.test', 'username' => 'doctora', 'password' => 'NuevaClave-2026', 'password_confirmation' => 'NuevaClave-2026',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($hash, $medico->usuario->fresh()->password);
    }

    public function test_el_formulario_solo_ofrece_especialidades_activas(): void
    {
        $this->actingAs($this->admin)->get(route('medicos.create'))
            ->assertOk()->assertSee('Cardiología')->assertSee('Pediatría')->assertDontSee('Homeopatía');
    }

    public function test_no_asigna_especialidades_inactivas_inexistentes_ni_vacias(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['especialidades' => [$this->inactiva->id_especialidad]]))
            ->assertSessionHasErrors(['especialidades.0' => 'No se puede asignar una especialidad inactiva.']);

        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['especialidades' => [999]]))
            ->assertSessionHasErrors(['especialidades.0' => 'Una de las especialidades seleccionadas no existe.']);

        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['especialidades' => []]))
            ->assertSessionHasErrors(['especialidades' => 'Selecciona al menos una especialidad.']);

        $this->assertDatabaseCount('medico', 0);
    }

    public function test_valida_campos_con_mensajes_en_espanol(): void
    {
        $this->medico('existente', [$this->cardiologia], ['ci' => '111', 'email' => 'repetido@ejemplo.test'], ['matricula' => 'MAT-REP', 'codigo' => 'COD-REP']);

        $this->actingAs($this->admin)->from(route('medicos.create'))
            ->post(route('medicos.store'), $this->datos([
                'nombre' => '', 'apellido' => str_repeat('a', 101), 'ci' => '111', 'email' => 'repetido@ejemplo.test',
                'username' => 'con espacios', 'estado' => 'Suspendido', 'matricula' => 'MAT-REP', 'codigo' => 'COD-REP',
                'telefono' => 'abc', 'fecha_nacimiento' => now()->addDay()->toDateString(),
            ]))
            ->assertRedirect(route('medicos.create'))
            ->assertSessionHasErrors([
                'nombre' => 'El campo nombre es obligatorio.',
                'apellido' => 'El campo apellido no debe superar los 100 caracteres.',
                'ci' => 'Ya existe un registro con este carnet de identidad.',
                'email' => 'Ya existe un registro con este correo electrónico.',
                'username' => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.',
                'estado' => 'El valor seleccionado en estado no es válido.',
                'matricula' => 'Ya existe un registro con este matrícula.',
                'codigo' => 'Ya existe un registro con este código.',
                'telefono' => 'El teléfono solo puede contener números, espacios y los signos + - ( ).',
                'fecha_nacimiento' => 'La fecha de nacimiento debe ser anterior a hoy.',
            ]);

        $this->actingAs($this->admin)->post(route('medicos.store'), $this->datos(['matricula' => 'MAT 01*', 'codigo' => str_repeat('c', 51)]))
            ->assertSessionHasErrors([
                'matricula' => 'La matrícula solo puede contener letras, números y los signos . / -',
                'codigo' => 'El campo código no debe superar los 50 caracteres.',
            ]);
    }

    public function test_el_formulario_conserva_las_especialidades_marcadas_tras_un_error(): void
    {
        $html = $this->actingAs($this->admin)->from(route('medicos.create'))->followingRedirects()
            ->post(route('medicos.store'), $this->datos(['nombre' => '', 'especialidades' => [$this->pediatria->id_especialidad]]))
            ->assertSee('No se pudo guardar')
            ->getContent();

        $this->assertMatchesRegularExpression('#value="' . $this->pediatria->id_especialidad . '"\s+checked#', $html);
        $this->assertDoesNotMatchRegularExpression('#value="' . $this->cardiologia->id_especialidad . '"\s+checked#', $html);
    }

    // --- Listado y detalle ---------------------------------------------------

    public function test_busca_y_filtra_por_especialidad_y_estado(): void
    {
        $this->medico('cardio', [$this->cardiologia], ['nombre' => 'Carla', 'apellido' => 'Méndez'], ['matricula' => 'MAT-777']);
        $this->medico('pedia', [$this->pediatria], ['nombre' => 'Jorge', 'apellido' => 'Antelo']);
        $this->medico('retirado', [$this->pediatria], ['nombre' => 'Raúl', 'apellido' => 'Vaca', 'estado' => 'Inactivo']);

        $this->actingAs($this->admin)->get(route('medicos.index', ['q' => 'mat-777']))
            ->assertOk()->assertSee('Méndez, Carla')->assertDontSee('Antelo, Jorge');

        $this->actingAs($this->admin)->get(route('medicos.index', ['especialidad' => $this->pediatria->id_especialidad]))
            ->assertSee('Antelo, Jorge')->assertSee('Vaca, Raúl')->assertDontSee('Méndez, Carla');

        $this->actingAs($this->admin)->get(route('medicos.index', ['estado' => 'Inactivo']))
            ->assertSee('Vaca, Raúl')->assertDontSee('Antelo, Jorge');

        $this->actingAs($this->admin)->get(route('medicos.index', ['especialidad' => $this->pediatria->id_especialidad, 'estado' => 'Activo']))
            ->assertSee('Antelo, Jorge')->assertDontSee('Vaca, Raúl')->assertSee('de 1 médico');
    }

    public function test_muestra_el_detalle(): void
    {
        $medico = $this->medico('detalle', [$this->cardiologia, $this->pediatria], ['nombre' => 'Patricia', 'apellido' => 'Rivero']);
        DB::table('horario_atencion')->insert(['id_medico' => $medico->id_medico, 'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_final' => '12:00']);

        $this->actingAs($this->admin)->get(route('medicos.show', $medico))
            ->assertOk()->assertSee('Patricia Rivero')->assertSee('Cardiología')->assertSee('Pediatría')
            ->assertSee('no se puede eliminar porque tiene 1 horario de atención');
    }

    // --- Edición, especialidades y estado ----------------------------------

    public function test_modifica_datos_y_especialidades(): void
    {
        $medico = $this->medico('editar', [$this->cardiologia]);

        $this->actingAs($this->admin)
            ->put(route('medicos.update', $medico), $this->datos(['email' => 'editar@ejemplo.test', 'username' => 'editar', 'matricula' => 'MAT-' . $medico->id_medico,
                'telefono' => '79999999', 'especialidades' => [$this->pediatria->id_especialidad]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('medicos.show', $medico))
            ->assertSessionHas('status', 'Datos del médico actualizados correctamente.');

        $this->assertDatabaseHas('users', ['id' => $medico->id_medico, 'telefono' => '79999999']);
        $this->assertSame([(int) $this->pediatria->id_especialidad], DB::table('especialidad_medico')->where('id_medico', $medico->id_medico)->pluck('id_especialidad')->map(fn ($v) => (int) $v)->all());
    }

    public function test_puede_conservar_una_especialidad_que_luego_se_desactivo(): void
    {
        $medico = $this->medico('conservar', [$this->inactiva]);

        $this->actingAs($this->admin)->get(route('medicos.edit', $medico))->assertSee('Homeopatía')->assertSee('inactiva: solo puede conservarse');

        $this->actingAs($this->admin)
            ->put(route('medicos.update', $medico), $this->datos(['email' => 'conservar@ejemplo.test', 'username' => 'conservar', 'ci' => null,
                'matricula' => null, 'codigo' => null, 'especialidades' => [$this->inactiva->id_especialidad, $this->cardiologia->id_especialidad]]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('especialidad_medico', 2);
    }

    public function test_no_quita_una_especialidad_con_citas_pendientes(): void
    {
        $medico = $this->medico('pendiente', [$this->cardiologia, $this->pediatria]);
        $this->cita($medico, $this->pediatria, now()->addDays(3)->toDateString());

        $this->actingAs($this->admin)->from(route('medicos.edit', $medico))
            ->put(route('medicos.update', $medico), $this->datos(['email' => 'pendiente@ejemplo.test', 'username' => 'pendiente', 'ci' => null,
                'matricula' => null, 'codigo' => null, 'especialidades' => [$this->cardiologia->id_especialidad]]))
            ->assertRedirect(route('medicos.edit', $medico))
            ->assertSessionHasErrors(['especialidades' => 'No se puede quitar «Pediatría»: el médico tiene 1 cita pendiente de esa especialidad.']);

        $this->assertDatabaseCount('especialidad_medico', 2);

        // Una cita pasada o cancelada no lo impide.
        DB::table('citas')->update(['estado' => 'Cancelada']);
        $this->actingAs($this->admin)
            ->put(route('medicos.update', $medico), $this->datos(['email' => 'pendiente@ejemplo.test', 'username' => 'pendiente', 'ci' => null,
                'matricula' => null, 'codigo' => null, 'especialidades' => [$this->cardiologia->id_especialidad]]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('especialidad_medico', 1);
    }

    public function test_desactiva_un_medico_sin_citas_pendientes(): void
    {
        $medico = $this->medico('desactivar', [$this->cardiologia]);

        $this->actingAs($this->admin)
            ->put(route('medicos.update', $medico), $this->datos(['email' => 'desactivar@ejemplo.test', 'username' => 'desactivar', 'ci' => null,
                'matricula' => null, 'codigo' => null, 'estado' => 'Inactivo', 'especialidades' => [$this->cardiologia->id_especialidad]]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $medico->id_medico, 'estado' => 'Inactivo']);
        $this->assertDatabaseHas('medico', ['id_medico' => $medico->id_medico]);
        $this->assertDatabaseCount('especialidad_medico', 1);
    }

    public function test_no_desactiva_con_citas_pendientes_ni_la_propia_cuenta(): void
    {
        $medico = $this->medico('ocupado', [$this->cardiologia]);
        $this->cita($medico, $this->cardiologia, now()->addDays(2)->toDateString(), 'Confirmada');

        $this->actingAs($this->admin)
            ->put(route('medicos.update', $medico), $this->datos(['email' => 'ocupado@ejemplo.test', 'username' => 'ocupado', 'ci' => null,
                'matricula' => null, 'codigo' => null, 'estado' => 'Inactivo', 'especialidades' => [$this->cardiologia->id_especialidad]]))
            ->assertSessionHasErrors(['estado' => 'No se puede desactivar: el médico tiene 1 cita pendiente. Cancélalas o reasígnalas antes.']);
        $this->assertDatabaseHas('users', ['id' => $medico->id_medico, 'estado' => 'Activo']);

        // La cuenta administrativa como médico: no puede desactivarse a sí misma.
        $propio = Medico::create(['id_medico' => $this->admin->id]);
        $propio->especialidades()->attach($this->cardiologia->id_especialidad);
        $this->actingAs($this->admin)
            ->put(route('medicos.update', $propio), $this->datos(['email' => 'admin@ejemplo.test', 'username' => 'admin', 'ci' => null,
                'matricula' => null, 'codigo' => null, 'estado' => 'Inactivo', 'especialidades' => [$this->cardiologia->id_especialidad]]))
            ->assertSessionHasErrors(['estado' => 'No puedes desactivar tu propia cuenta.']);
    }

    // --- Eliminación -------------------------------------------------------

    public function test_elimina_un_medico_sin_actividad(): void
    {
        $medico = $this->medico('libre', [$this->cardiologia]);
        $id = $medico->id_medico;

        $this->actingAs($this->admin)->get(route('medicos.show', $medico))->assertSee('Eliminar definitivamente')->assertSee('las especialidades no se borran');

        $this->actingAs($this->admin)->delete(route('medicos.destroy', $medico))
            ->assertRedirect(route('medicos.index'))
            ->assertSessionHas('status', 'Médico eliminado correctamente.');

        $this->assertDatabaseMissing('medico', ['id_medico' => $id]);
        $this->assertDatabaseMissing('users', ['id' => $id]);
        $this->assertDatabaseMissing('especialidad_medico', ['id_medico' => $id]);
        $this->assertDatabaseHas('especialidad', ['id_especialidad' => $this->cardiologia->id_especialidad]);
    }

    public function test_no_elimina_un_medico_con_citas_horarios_o_bloqueos(): void
    {
        $conCita = $this->medico('con_cita', [$this->cardiologia]);
        $this->cita($conCita, $this->cardiologia, now()->subDays(10)->toDateString(), 'Atendida');

        $conHorario = $this->medico('con_horario', [$this->cardiologia]);
        DB::table('horario_atencion')->insert(['id_medico' => $conHorario->id_medico, 'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_final' => '12:00']);

        $conBloqueo = $this->medico('con_bloqueo', [$this->cardiologia]);
        DB::table('bloqueo_agenda')->insert(['id_medico' => $conBloqueo->id_medico, 'fecha' => '2026-11-10', 'hora_inicio' => '08:00', 'hora_final' => '10:00']);

        $casos = [
            [$conCita, 'tiene 1 cita registrada', 'citas'],
            [$conHorario, 'tiene 1 horario de atención', 'horario_atencion'],
            [$conBloqueo, 'tiene 1 bloqueo de agenda', 'bloqueo_agenda'],
        ];

        foreach ($casos as [$medico, $motivo, $tabla]) {
            $this->actingAs($this->admin)->get(route('medicos.show', $medico))->assertSee($motivo)->assertDontSee('Eliminar definitivamente');

            $this->actingAs($this->admin)->delete(route('medicos.destroy', $medico))
                ->assertSessionHas('error', "No se puede eliminar al médico porque {$motivo}. Puedes desactivarlo desde «Editar».");

            $this->assertDatabaseHas('medico', ['id_medico' => $medico->id_medico]);
            $this->assertDatabaseHas($tabla, ['id_medico' => $medico->id_medico]);
        }
    }

    public function test_si_tambien_es_paciente_solo_se_quita_la_ficha_de_medico(): void
    {
        $medico = $this->medico('ambos', [$this->cardiologia]);
        DB::table('paciente')->insert(['id_paciente' => $medico->id_medico, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->actingAs($this->admin)->delete(route('medicos.destroy', $medico))
            ->assertSessionHas('status', 'Se eliminó la ficha de médico y sus especialidades asignadas. La cuenta de acceso se conservó porque también es paciente de la clínica.');

        $this->assertDatabaseMissing('medico', ['id_medico' => $medico->id_medico]);
        $this->assertDatabaseHas('users', ['id' => $medico->id_medico]);
        $this->assertDatabaseHas('paciente', ['id_paciente' => $medico->id_medico]);
    }

    // --- Vistas --------------------------------------------------------------

    public function test_las_vistas_usan_csrf_no_contienen_javascript_y_marcan_el_menu(): void
    {
        $medico = $this->medico('vista', [$this->cardiologia]);

        foreach ([route('medicos.index'), route('medicos.create'), route('medicos.show', $medico), route('medicos.edit', $medico)] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            foreach (['<script', 'x-data', 'x-show', 'onclick', '@click', 'build/assets'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $html, "{$url} contiene «{$prohibido}»");
            }

            preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $menu);
            $this->assertMatchesRegularExpression('#href="' . preg_quote(route('medicos.index'), '#') . '"\s*aria-current="page"#', $menu[1]);
        }

        $this->actingAs($this->admin)->get(route('medicos.create'))->assertSee('name="_token"', false)->assertSee('name="especialidades[]"', false);
        $this->actingAs($this->admin)->get(route('medicos.edit', $medico))->assertSee('name="_method" value="PUT"', false);
        $this->actingAs($this->admin)->get(route('medicos.show', $medico))->assertSee('name="_method" value="DELETE"', false);
    }
}
