<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eliminación de la propia cuenta desde «Mi perfil» (ProfileController::destroy).
 */
class EliminacionCuentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        // Como en clinica_db: la cuenta administrativa principal es id 1 / «admin».
        $this->usuario('admin');
    }

    private function usuario(string $username): User
    {
        return User::create([
            'nombre' => 'Usuario',
            'apellido' => 'Prueba',
            'email' => "{$username}@ejemplo.test",
            'username' => $username,
            'password' => 'password',
        ]);
    }

    public function test_una_cuenta_sin_vinculos_se_puede_eliminar(): void
    {
        $usuario = $this->usuario('libre');

        $this->actingAs($usuario)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $usuario->id]);
    }

    public function test_la_contrasena_sigue_siendo_obligatoria(): void
    {
        $usuario = $this->usuario('clave');

        $this->actingAs($usuario)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'incorrecta'])
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseHas('users', ['id' => $usuario->id]);
    }

    public function test_un_paciente_con_citas_no_borra_su_cuenta_ni_sus_citas_y_pagos(): void
    {
        $usuario = $this->usuario('paciente');
        Paciente::create(['id_paciente' => $usuario->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $medico = $this->usuario('medico');
        DB::table('medico')->insert(['id_medico' => $medico->id]);
        $idEspecialidad = DB::table('especialidad')->insertGetId(['nombre' => 'Cardiología'], 'id_especialidad');
        $idCita = DB::table('citas')->insertGetId([
            'codigo' => 'C-9', 'id_paciente' => $usuario->id, 'id_medico' => $medico->id,
            'id_especialidad' => $idEspecialidad, 'fecha_cita' => '2026-11-02', 'hora_inicio' => '09:00', 'hora_fin' => '09:30',
        ], 'id_cita');
        DB::table('pago')->insert(['id_cita' => $idCita, 'monto' => 150]);

        $this->actingAs($usuario)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrorsIn('userDeletion', [
                'cuenta' => 'No puedes eliminar tu cuenta porque tiene una ficha de paciente en la clínica. Solicita la baja al personal de la clínica.',
            ]);

        $this->assertAuthenticatedAs($usuario);
        $this->assertDatabaseHas('users', ['id' => $usuario->id]);
        $this->assertDatabaseHas('citas', ['id_cita' => $idCita]);
        $this->assertDatabaseHas('pago', ['id_cita' => $idCita]);
    }

    public function test_un_medico_con_citas_no_borra_su_cuenta(): void
    {
        $medico = $this->usuario('doctora');
        DB::table('medico')->insert(['id_medico' => $medico->id]);

        $this->actingAs($medico)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'cuenta');

        $this->assertDatabaseHas('medico', ['id_medico' => $medico->id]);
    }

    public function test_un_usuario_con_rol_asignado_no_borra_su_cuenta(): void
    {
        $this->seed(PermisosPacientesSeeder::class);
        $admin = $this->usuario('jefa_admin');
        DB::table('usuario_rol')->insert([
            'id_usuario' => $admin->id,
            'id_rol' => DB::table('rol')->where('nombre', 'Administrador')->value('id_rol'),
        ]);

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', [
                'cuenta' => 'No puedes eliminar tu cuenta porque también es rol «Administrador». Solicita la baja al personal de la clínica.',
            ]);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $admin->id]);
    }

    public function test_el_perfil_muestra_el_motivo_sin_abrir_la_ventana(): void
    {
        $usuario = $this->usuario('paciente2');
        Paciente::create(['id_paciente' => $usuario->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->actingAs($usuario)
            ->from(route('profile.edit'))
            ->followingRedirects()
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertOk()
            ->assertSee('No puedes eliminar tu cuenta porque tiene una ficha de paciente en la clínica.')
            ->assertDontSee('id="confirm-user-deletion" class="form-stack modal modal--2xl" open', false);
    }
}
