<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Sesiones de cuentas desactivadas, nombre de usuario reservado y correo de contraseña.
 */
class SeguridadSesionTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $paciente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->paciente = $this->pacienteDe('paciente01');
    }

    // --- Cuentas desactivadas -----------------------------------------------------

    public function test_una_cuenta_desactivada_pierde_su_sesion_en_la_siguiente_peticion(): void
    {
        $this->post(route('login'), ['email' => $this->paciente->email, 'password' => 'password'])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();

        DB::table('users')->where('id', $this->paciente->id)->update(['estado' => User::ESTADO_INACTIVO]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Tu sesión se cerró porque la cuenta no está activa. Contacta con la clínica.']);
        $this->assertGuest();

        $evento = Bitacora::where('detalle', 'Sesión cerrada automáticamente: la cuenta no está activa.')->sole();
        $this->assertSame([$this->paciente->id, 'Cierre de sesión'], [(int) $evento->id_usuario, $evento->accion]);

        // Tampoco puede volver a entrar.
        $this->post(route('login'), ['email' => $this->paciente->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_tampoco_puede_escribir_con_la_sesion_abierta(): void
    {
        $this->actingAs($this->admin->fresh());
        DB::table('users')->where('id', $this->admin->id)->update(['estado' => User::ESTADO_INACTIVO]);

        $this->post(route('especialidades.store'), ['nombre' => 'Geriatría', 'estado' => 'Activo'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('especialidad', ['nombre' => 'Geriatría']);
    }

    public function test_una_cuenta_activa_conserva_su_sesion_y_los_invitados_no_cambian(): void
    {
        $this->get(route('login'))->assertOk();

        $this->actingAs($this->paciente)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->paciente)->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($this->paciente);
        $this->assertSame(0, Bitacora::count());
    }

    // --- Usuario reservado ----------------------------------------------------------

    public function test_nadie_puede_tomar_el_usuario_admin_aunque_este_libre(): void
    {
        DB::table('users')->where('id', $this->admin->id)->update(['username' => 'principal']);
        $recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $datos = ['nombre' => 'Ana', 'apellido' => 'Rojas', 'email' => 'ana@ejemplo.test'];

        foreach (['admin', 'ADMIN', 'Admin'] as $nombre) {
            $this->actingAs($recepcion)->post(route('pacientes.store'), [...$datos, 'username' => $nombre])
                ->assertSessionHasErrors(['username' => 'Ese nombre de usuario está reservado.']);
            $this->actingAs($recepcion)->put(route('pacientes.update', $this->paciente->id), [...$datos, 'username' => $nombre])
                ->assertSessionHasErrors(['username' => 'Ese nombre de usuario está reservado.']);
        }
        $this->assertSame('paciente01', $this->paciente->fresh()->username);
        $this->assertSame(User::PERFIL_PACIENTE, $this->paciente->fresh()->perfil());

        $especialidad = $this->especialidadActiva();
        $medico = $this->medicoCon('medico01', $especialidad);
        $datosMedico = [...$datos, 'username' => 'admin', 'estado' => 'Activo', 'especialidades' => [$especialidad->id_especialidad]];
        $this->actingAs($this->admin->fresh())->post(route('medicos.store'), [...$datosMedico, 'password' => 'Clave-2026-x', 'password_confirmation' => 'Clave-2026-x'])
            ->assertSessionHasErrors(['username' => 'Ese nombre de usuario está reservado.']);
        $this->actingAs($this->admin->fresh())->put(route('medicos.update', $medico->id), $datosMedico)
            ->assertSessionHasErrors(['username' => 'Ese nombre de usuario está reservado.']);
        $this->assertSame('medico01', $medico->fresh()->username);
    }

    public function test_la_cuenta_principal_conserva_su_usuario_al_editar_su_ficha(): void
    {
        DB::table('paciente')->insert(['id_paciente' => $this->admin->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->actingAs($this->admin->fresh())->put(route('pacientes.update', $this->admin->id), [
            'nombre' => 'Admin', 'apellido' => 'Pro', 'email' => $this->admin->email, 'username' => 'admin',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Pro', $this->admin->fresh()->apellido);
    }

    // --- Correo de contraseña ----------------------------------------------------------

    public function test_el_correo_de_contrasena_saluda_con_el_nombre(): void
    {
        $correo = (new ResetPassword('token-de-prueba'))->toMail($this->paciente);

        $this->assertSame('¡Hola Paciente01!', $correo->greeting);
    }
}
