<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ninguna cuenta puede eliminarse a sí misma desde «Mi perfil».
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

        $this->seed(PermisosPacientesSeeder::class);
    }

    private function usuario(string $username, ?string $rol = null): User
    {
        $usuario = User::create([
            'nombre' => 'Usuario',
            'apellido' => 'Prueba',
            'email' => "{$username}@ejemplo.test",
            'username' => $username,
            'password' => 'password',
        ]);

        if ($rol) {
            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol')]);
        }

        return $usuario->fresh();
    }

    /** @return list<User> una cuenta de cada perfil */
    private function cuentas(): array
    {
        $paciente = $this->usuario('paciente');
        Paciente::create(['id_paciente' => $paciente->id, 'inasistencia' => 0, 'bloqueado' => false]);
        $medico = $this->usuario('medico');
        DB::table('medico')->insert(['id_medico' => $medico->id]);

        return [$this->usuario('admin', 'Administrador'), $this->usuario('recepcion', 'Recepcionista'), $medico, $paciente, $this->usuario('sin_perfil')];
    }

    public function test_no_existe_la_ruta_para_eliminar_la_cuenta(): void
    {
        $this->assertFalse(Route::has('profile.destroy'));

        foreach ($this->cuentas() as $cuenta) {
            $this->actingAs($cuenta)->delete('/profile', ['password' => 'password'])->assertStatus(405);

            $this->assertAuthenticatedAs($cuenta);
            $this->assertDatabaseHas('users', ['id' => $cuenta->id]);
        }
    }

    public function test_pacientes_y_medicos_no_se_pueden_eliminar(): void
    {
        $this->assertFalse(Route::has('pacientes.destroy'));
        $this->assertFalse(Route::has('medicos.destroy'));

        [$admin, , $medico, $paciente] = $this->cuentas();

        $this->actingAs($admin)->delete('/pacientes/' . $paciente->id)->assertStatus(405);
        $this->actingAs($admin)->delete('/medicos/' . $medico->id)->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $paciente->id]);
        $this->assertDatabaseHas('paciente', ['id_paciente' => $paciente->id]);
        $this->assertDatabaseHas('users', ['id' => $medico->id]);
        $this->assertDatabaseHas('medico', ['id_medico' => $medico->id]);
    }

    public function test_ningun_perfil_ve_la_opcion_y_todos_pueden_cambiar_su_contrasena(): void
    {
        foreach ($this->cuentas() as $cuenta) {
            $this->actingAs($cuenta)->get(route('profile.edit'))->assertOk()
                ->assertDontSee('Delete Account')->assertDontSee('confirm-user-deletion')
                ->assertSee('action="' . route('password.update') . '"', false);
        }
    }
}
