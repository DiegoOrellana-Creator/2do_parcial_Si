<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\User;
use App\Services\DependenciasUsuario;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Protección de la cuenta administrativa principal e inicio de sesión de cuentas inactivas.
 */
class SeguridadCuentasTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_LOGIN = 'No se pudo iniciar sesión con esos datos. Verifica tu correo y contraseña o contacta con la clínica.';

    private User $principal;

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        // Como en clinica_db: id 1, username «admin», sin roles asignados.
        $this->principal = $this->usuario('admin');
        $this->assertSame(DependenciasUsuario::CUENTA_PRINCIPAL_ID, $this->principal->id);
    }

    private function usuario(string $username, string $estado = 'Activo'): User
    {
        return User::create([
            'nombre' => 'Usuario',
            'apellido' => 'Prueba',
            'email' => "{$username}@ejemplo.test",
            'username' => $username,
            'password' => 'password',
            'estado' => $estado,
        ]);
    }

    // --- Cuenta administrativa principal ------------------------------------

    public function test_solo_la_cuenta_principal_se_reconoce_como_tal(): void
    {
        $this->assertTrue((new DependenciasUsuario(1))->esCuentaPrincipal());
        $this->assertFalse((new DependenciasUsuario($this->usuario('otro')->id))->esCuentaPrincipal());
    }

    // --- Inicio de sesión ----------------------------------------------------

    public function test_una_cuenta_activa_inicia_sesion_y_accede_al_dashboard(): void
    {
        $activa = $this->usuario('activa');

        $this->post(route('login'), ['email' => 'activa@ejemplo.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($activa);
        $this->get(route('dashboard'))->assertOk()->assertSee('Hola, Usuario');
    }

    public function test_una_cuenta_inactiva_no_inicia_sesion_aunque_la_contrasena_sea_correcta(): void
    {
        $this->usuario('inactiva', 'Inactivo');

        $this->from(route('login'))
            ->post(route('login'), ['email' => 'inactiva@ejemplo.test', 'password' => 'password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => self::MENSAJE_LOGIN]);

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_el_mensaje_no_distingue_entre_cuenta_inactiva_y_contrasena_incorrecta(): void
    {
        $this->usuario('inactiva2', 'Inactivo');
        $this->usuario('activa2');

        $inactiva = $this->post(route('login'), ['email' => 'inactiva2@ejemplo.test', 'password' => 'password']);
        $incorrecta = $this->post(route('login'), ['email' => 'activa2@ejemplo.test', 'password' => 'otra-clave']);
        $inexistente = $this->post(route('login'), ['email' => 'nadie@ejemplo.test', 'password' => 'password']);

        foreach ([$inactiva, $incorrecta, $inexistente] as $respuesta) {
            $respuesta->assertSessionHasErrors(['email' => self::MENSAJE_LOGIN]);
        }

        $this->assertGuest();
    }

    public function test_un_estado_distinto_de_activo_tampoco_inicia_sesion(): void
    {
        $this->usuario('minuscula', 'activo');

        $this->post(route('login'), ['email' => 'minuscula@ejemplo.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => self::MENSAJE_LOGIN]);

        $this->assertGuest();
    }

    public function test_iniciar_sesion_no_modifica_al_usuario(): void
    {
        $antes = (array) DB::table('users')->where('username', 'admin')->first();

        $this->post(route('login'), ['email' => 'admin@ejemplo.test', 'password' => 'password'])->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'admin@ejemplo.test', 'password' => 'incorrecta']);

        $this->assertSame($antes, (array) DB::table('users')->where('username', 'admin')->first());
    }

    // --- Cierre de sesión ----------------------------------------------------

    public function test_el_cierre_de_sesion_sigue_funcionando(): void
    {
        $this->actingAs($this->principal)->get(route('dashboard'))->assertOk();

        $this->post(route('logout'))->assertRedirect('/login');

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
