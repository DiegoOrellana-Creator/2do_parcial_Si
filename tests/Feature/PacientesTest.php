<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PacientesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->seed(PermisosPacientesSeeder::class);
    }

    private function usuario(array $datos = [], ?string $rol = null): User
    {
        static $n = 0;
        $n++;

        $usuario = User::create(array_merge([
            'nombre' => 'Usuario',
            'apellido' => "Prueba {$n}",
            'email' => "usuario{$n}@ejemplo.test",
            'username' => "usuario{$n}",
            'password' => 'password',
            'estado' => 'Activo',
        ], $datos));

        if ($rol) {
            DB::table('usuario_rol')->insert([
                'id_usuario' => $usuario->id,
                'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol'),
            ]);
        }

        return $usuario;
    }

    private function paciente(array $datosUsuario = [], array $datosPaciente = []): Paciente
    {
        $usuario = $this->usuario($datosUsuario);

        return Paciente::create(array_merge([
            'id_paciente' => $usuario->id,
            'inasistencia' => 0,
            'bloqueado' => false,
        ], $datosPaciente));
    }

    private function datosValidos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Ana',
            'apellido' => 'Rojas Vaca',
            'ci' => '1234567',
            'fecha_nacimiento' => '1990-05-12',
            'sexo' => 'Femenino',
            'telefono' => '+591 70000000',
            'direccion' => 'Av. Ejemplo 123',
            'email' => 'ana.rojas@ejemplo.test',
            'username' => 'ana_rojas',
            'nro_ficha' => 'F-0001',
            'tipo_sangre' => 'O+',
            'alergias' => 'Penicilina',
            'enfermedades_cronicas' => '',
            'medicamentos' => '',
            'antecedentes' => '',
            'contacto_nombre' => 'Luis Rojas',
            'contacto_parentesco' => 'Hermano',
            'contacto_telefono' => '70000001',
        ], $cambios);
    }

    public function test_un_visitante_es_redirigido_al_login(): void
    {
        $this->get(route('pacientes.index'))->assertRedirect(route('login'));
        $this->post(route('pacientes.store'), $this->datosValidos())->assertRedirect(route('login'));
    }

    public function test_un_usuario_sin_permisos_no_accede_ni_ve_el_enlace(): void
    {
        $usuario = $this->usuario();
        $paciente = $this->paciente();

        $this->actingAs($usuario)->get(route('pacientes.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('pacientes.create'))->assertForbidden();
        $this->actingAs($usuario)->get(route('pacientes.show', $paciente))->assertForbidden();
        $this->actingAs($usuario)->post(route('pacientes.store'), $this->datosValidos())->assertForbidden();
        $this->actingAs($usuario)->delete(route('pacientes.destroy', $paciente))->assertForbidden();

        $this->actingAs($usuario)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('pacientes.index'), false)
            ->assertSee('Sin acceso');
    }

    public function test_un_usuario_inactivo_pierde_sus_permisos(): void
    {
        $usuario = $this->usuario(['estado' => 'Inactivo'], 'Administrador');

        $this->actingAs($usuario)->get(route('pacientes.index'))->assertForbidden();
    }

    public function test_la_recepcionista_puede_listar_y_registrar_pero_no_eliminar(): void
    {
        $recepcionista = $this->usuario([], 'Recepcionista');
        $paciente = $this->paciente();

        $this->actingAs($recepcionista)->get(route('pacientes.index'))->assertOk();
        $this->actingAs($recepcionista)->get(route('pacientes.create'))->assertOk();
        $this->actingAs($recepcionista)->get(route('pacientes.edit', $paciente))->assertOk();
        $this->actingAs($recepcionista)->delete(route('pacientes.destroy', $paciente))->assertForbidden();

        $this->actingAs($recepcionista)->get(route('pacientes.show', $paciente))
            ->assertOk()
            ->assertDontSee('Eliminar definitivamente');

        $this->actingAs($recepcionista)->get(route('dashboard'))
            ->assertSee(route('pacientes.index'), false)
            ->assertSee(route('pacientes.create'), false);
    }

    public function test_registra_un_paciente_con_su_informacion_y_contacto(): void
    {
        $admin = $this->usuario([], 'Administrador');

        $respuesta = $this->actingAs($admin)->post(route('pacientes.store'), $this->datosValidos());

        $usuario = User::where('email', 'ana.rojas@ejemplo.test')->firstOrFail();
        $respuesta->assertRedirect(route('pacientes.show', $usuario->id))
            ->assertSessionHas('status', 'Paciente registrado correctamente.');

        $this->assertSame('ana_rojas', $usuario->username);
        $this->assertSame('Activo', $usuario->fresh()->estado);
        $this->assertNotEmpty($usuario->password);
        $this->assertFalse(Hash::check('', $usuario->password));

        $this->assertDatabaseHas('paciente', ['id_paciente' => $usuario->id, 'nro_ficha' => 'F-0001', 'inasistencia' => 0, 'bloqueado' => false]);
        $this->assertDatabaseHas('informacion_medica', ['id_paciente' => $usuario->id, 'tipo_sangre' => 'O+', 'alergias' => 'Penicilina']);
        $this->assertDatabaseHas('contacto_emergencia', ['id_paciente' => $usuario->id, 'nombre' => 'Luis Rojas', 'parentesco' => 'Hermano']);
    }

    public function test_no_crea_informacion_medica_ni_contacto_vacios(): void
    {
        $admin = $this->usuario([], 'Administrador');

        $this->actingAs($admin)->post(route('pacientes.store'), $this->datosValidos([
            'tipo_sangre' => '', 'alergias' => '', 'contacto_nombre' => '', 'contacto_parentesco' => '', 'contacto_telefono' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('informacion_medica', 0);
        $this->assertDatabaseCount('contacto_emergencia', 0);
    }

    public function test_valida_los_campos_con_mensajes_en_espanol(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $this->paciente(['email' => 'repetido@ejemplo.test', 'username' => 'repetido', 'ci' => '999']);

        $this->actingAs($admin)
            ->from(route('pacientes.create'))
            ->post(route('pacientes.store'), $this->datosValidos([
                'nombre' => '',
                'email' => 'repetido@ejemplo.test',
                'username' => 'con espacios',
                'ci' => '999',
                'sexo' => 'Desconocido',
                'fecha_nacimiento' => now()->addDay()->toDateString(),
                'telefono' => 'abc',
                'tipo_sangre' => 'Z+',
                'contacto_nombre' => '',
            ]))
            ->assertRedirect(route('pacientes.create'))
            ->assertSessionHasErrors([
                'nombre' => 'El campo nombre es obligatorio.',
                'email' => 'Ya existe un registro con este correo electrónico.',
                'username' => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.',
                'ci' => 'Ya existe un registro con este carnet de identidad.',
                'sexo' => 'El valor seleccionado en sexo no es válido.',
                'fecha_nacimiento' => 'La fecha de nacimiento no puede ser posterior a hoy.',
                'telefono' => 'El teléfono solo puede contener números, espacios y los signos + - ( ).',
                'tipo_sangre' => 'El valor seleccionado en tipo de sangre no es válido.',
                'contacto_nombre' => 'Indica el nombre del contacto de emergencia si registras su parentesco o teléfono.',
            ]);

        $this->assertDatabaseCount('paciente', 1);
    }

    public function test_el_listado_busca_y_pagina_con_solicitudes_get(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $this->paciente(['nombre' => 'Lucía', 'apellido' => 'Pinto', 'ci' => '555111']);
        $this->paciente(['nombre' => 'Jorge', 'apellido' => 'Antelo'], ['nro_ficha' => 'F-77']);

        $this->actingAs($admin)->get(route('pacientes.index', ['q' => 'pinto']))
            ->assertOk()->assertSee('Pinto, Lucía')->assertDontSee('Antelo, Jorge');

        $this->actingAs($admin)->get(route('pacientes.index', ['q' => 'f-77']))
            ->assertOk()->assertSee('Antelo, Jorge')->assertDontSee('Pinto, Lucía');

        $this->actingAs($admin)->get(route('pacientes.index', ['q' => '555111']))
            ->assertOk()->assertSee('Pinto, Lucía');

        for ($i = 0; $i < 11; $i++) {
            $this->paciente(['apellido' => "Zeta {$i}"]);
        }

        $this->actingAs($admin)->get(route('pacientes.index'))
            ->assertOk()->assertSee('de 13 pacientes')->assertSee('aria-label="Paginación"', false);

        $this->actingAs($admin)->get(route('pacientes.index', ['page' => 2]))
            ->assertOk()->assertSee('Mostrando 11–13 de 13');
    }

    public function test_muestra_el_detalle_completo(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $this->actingAs($admin)->post(route('pacientes.store'), $this->datosValidos());
        $paciente = Paciente::firstWhere('nro_ficha', 'F-0001');

        $this->actingAs($admin)->get(route('pacientes.show', $paciente))
            ->assertOk()
            ->assertSee('Ana Rojas Vaca')
            ->assertSee('1234567')
            ->assertSee('12/05/1990')
            ->assertSee('Penicilina')
            ->assertSee('Luis Rojas')
            ->assertSee('El paciente todavía no tiene citas registradas.')
            ->assertSee('Eliminar definitivamente');
    }

    public function test_actualiza_un_paciente_y_permite_conservar_sus_datos_unicos(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $this->actingAs($admin)->post(route('pacientes.store'), $this->datosValidos());
        $paciente = Paciente::firstWhere('nro_ficha', 'F-0001');

        $this->actingAs($admin)
            ->put(route('pacientes.update', $paciente), $this->datosValidos([
                'telefono' => '70999999',
                'tipo_sangre' => 'A-',
                'contacto_nombre' => '', 'contacto_parentesco' => '', 'contacto_telefono' => '',
            ]))
            ->assertRedirect(route('pacientes.show', $paciente))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $paciente->id_paciente, 'telefono' => '70999999', 'email' => 'ana.rojas@ejemplo.test']);
        $this->assertDatabaseHas('informacion_medica', ['id_paciente' => $paciente->id_paciente, 'tipo_sangre' => 'A-']);
        $this->assertDatabaseCount('contacto_emergencia', 0);
    }

    public function test_elimina_un_paciente_sin_citas(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = $this->paciente();
        $id = $paciente->id_paciente;

        $this->actingAs($admin)->delete(route('pacientes.destroy', $paciente))
            ->assertRedirect(route('pacientes.index'))
            ->assertSessionHas('status', 'Paciente eliminado correctamente.');

        $this->assertDatabaseMissing('paciente', ['id_paciente' => $id]);
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    public function test_no_elimina_un_paciente_con_citas(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = $this->paciente();
        $medico = $this->usuario();
        DB::table('medico')->insert(['id_medico' => $medico->id]);
        $idEspecialidad = DB::table('especialidad')->insertGetId(['nombre' => 'Pediatría'], 'id_especialidad');
        DB::table('citas')->insert([
            'codigo' => 'C-1', 'id_paciente' => $paciente->id_paciente, 'id_medico' => $medico->id,
            'id_especialidad' => $idEspecialidad, 'fecha_cita' => '2026-10-20', 'hora_inicio' => '08:30', 'hora_fin' => '09:00',
        ]);

        $this->actingAs($admin)->get(route('pacientes.show', $paciente))
            ->assertOk()->assertSee('C-1')->assertSee('Pediatría')->assertDontSee('Eliminar definitivamente');

        $this->actingAs($admin)->from(route('pacientes.show', $paciente))
            ->delete(route('pacientes.destroy', $paciente))
            ->assertRedirect(route('pacientes.show', $paciente))
            ->assertSessionHas('error', 'No se puede eliminar al paciente porque tiene 1 cita registrada.');

        $this->assertDatabaseHas('paciente', ['id_paciente' => $paciente->id_paciente]);
        $this->assertDatabaseHas('citas', ['codigo' => 'C-1']);
    }

    public function test_no_elimina_un_paciente_con_bloqueos_registrados(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = $this->paciente();
        DB::table('bloqueo_paciente')->insert(['id_paciente' => $paciente->id_paciente, 'motivo' => 'Inasistencias']);

        $this->actingAs($admin)->get(route('pacientes.show', $paciente))
            ->assertSee('no se puede eliminar porque tiene 1 bloqueo registrado')
            ->assertDontSee('Eliminar definitivamente');

        $this->actingAs($admin)->delete(route('pacientes.destroy', $paciente))
            ->assertSessionHas('error', 'No se puede eliminar al paciente porque tiene 1 bloqueo registrado.');

        $this->assertDatabaseCount('bloqueo_paciente', 1);
    }

    public function test_si_la_cuenta_tiene_un_rol_en_usuario_rol_solo_se_quita_la_ficha(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $recepcionista = $this->usuario([], 'Recepcionista');
        $paciente = Paciente::create(['id_paciente' => $recepcionista->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->actingAs($admin)->get(route('pacientes.show', $paciente))
            ->assertSee('se conservarán porque también es rol «Recepcionista»');

        $this->actingAs($admin)->delete(route('pacientes.destroy', $paciente))
            ->assertRedirect(route('pacientes.index'))
            ->assertSessionHas('status', 'Se eliminó la ficha de paciente. La cuenta de acceso se conservó porque también es rol «Recepcionista».');

        $this->assertDatabaseMissing('paciente', ['id_paciente' => $recepcionista->id]);
        $this->assertDatabaseHas('users', ['id' => $recepcionista->id]);
        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $recepcionista->id]);
    }

    public function test_si_la_cuenta_figura_en_la_auditoria_se_conserva_su_autoria(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = $this->paciente();
        DB::table('bitacora')->insert(['id_usuario' => $paciente->id_paciente, 'accion' => 'Inicio de sesión']);

        $this->actingAs($admin)->delete(route('pacientes.destroy', $paciente))
            ->assertSessionHas('status', 'Se eliminó la ficha de paciente. La cuenta de acceso se conservó porque figura como autor de 1 registro de auditoría.');

        $this->assertDatabaseHas('users', ['id' => $paciente->id_paciente]);
        $this->assertDatabaseHas('bitacora', ['id_usuario' => $paciente->id_paciente]);
    }

    public function test_la_confirmacion_detalla_lo_que_se_eliminara(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $this->actingAs($admin)->post(route('pacientes.store'), $this->datosValidos());
        $paciente = Paciente::firstWhere('nro_ficha', 'F-0001');
        DB::table('notificacion')->insert(['id_usuario' => $paciente->id_paciente, 'mensaje' => 'Recordatorio']);

        $this->actingAs($admin)->get(route('pacientes.show', $paciente))
            ->assertSeeInOrder([
                'Se eliminará definitivamente:',
                'La ficha de paciente (n.º F-0001).',
                'Su información médica.',
                '1 contacto de emergencia.',
                'Su cuenta de acceso (ana_rojas) con sus datos personales.',
                '1 notificación.',
                'No tiene citas ni bloqueos registrados.',
            ]);
    }

    public function test_si_el_paciente_tambien_es_personal_solo_se_quita_su_ficha(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = $this->paciente();
        DB::table('medico')->insert(['id_medico' => $paciente->id_paciente]);

        $this->actingAs($admin)->delete(route('pacientes.destroy', $paciente))->assertRedirect(route('pacientes.index'));

        $this->assertDatabaseMissing('paciente', ['id_paciente' => $paciente->id_paciente]);
        $this->assertDatabaseHas('users', ['id' => $paciente->id_paciente]);
        $this->assertDatabaseHas('medico', ['id_medico' => $paciente->id_paciente]);
    }

    public function test_no_permite_eliminarse_a_si_mismo(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = Paciente::create(['id_paciente' => $admin->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->actingAs($admin)->delete(route('pacientes.destroy', $paciente))
            ->assertSessionHas('error', 'No puedes eliminar tu propio registro de paciente.');

        $this->assertDatabaseHas('paciente', ['id_paciente' => $admin->id]);
    }

    public function test_las_vistas_usan_csrf_y_no_contienen_javascript(): void
    {
        $admin = $this->usuario([], 'Administrador');
        $paciente = $this->paciente();

        $paginas = [
            route('pacientes.index'),
            route('pacientes.create'),
            route('pacientes.show', $paciente),
            route('pacientes.edit', $paciente),
        ];

        foreach ($paginas as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            foreach (['<script', 'x-data', 'x-show', 'onclick', '@click', 'build/assets', 'tailwind'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $html, "{$url} contiene «{$prohibido}»");
            }
        }

        $this->actingAs($admin)->get(route('pacientes.create'))->assertSee('name="_token"', false);
        $this->actingAs($admin)->get(route('pacientes.edit', $paciente))
            ->assertSee('name="_token"', false)->assertSee('name="_method" value="PUT"', false);
        $this->actingAs($admin)->get(route('pacientes.show', $paciente))
            ->assertSee('name="_method" value="DELETE"', false);
    }
}
