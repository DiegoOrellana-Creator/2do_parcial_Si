<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Módulo Usuarios: exclusivo del administrador; gestiona cuentas del personal y no borra ninguna.
 */
class UsuariosTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $recepcion;

    private User $medico;

    private User $paciente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        $this->medico = $this->medicoCon('medico01', $this->especialidadActiva());
        $this->paciente = $this->pacienteDe('paciente01');
    }

    private function datosNuevo(array $cambios = []): array
    {
        return array_merge([
            'tipo' => 'Recepcionista', 'nombre' => 'Paola', 'apellido' => 'Saucedo', 'ci' => '4821563', 'telefono' => '70012345', 'direccion' => 'Av. Ejemplo',
            'email' => 'paola@ejemplo.test', 'username' => 'psaucedo', 'password' => 'Clave-Nueva-2026', 'password_confirmation' => 'Clave-Nueva-2026',
        ], $cambios);
    }

    private function datosDe(User $usuario, array $cambios = []): array
    {
        return array_merge(['nombre' => $usuario->nombre, 'apellido' => $usuario->apellido, 'email' => $usuario->email], $cambios);
    }

    // --- Acceso ---------------------------------------------------------------------

    public function test_solo_el_administrador_entra(): void
    {
        $this->get(route('usuarios.index'))->assertRedirect(route('login'));

        foreach ([$this->recepcion, $this->medico, $this->paciente, $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get(route('usuarios.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('usuarios.create'))->assertForbidden();
            $this->actingAs($otro)->post(route('usuarios.store'), $this->datosNuevo())->assertForbidden();
            $this->actingAs($otro)->get(route('usuarios.edit', $this->recepcion))->assertForbidden();
            $this->actingAs($otro)->put(route('usuarios.update', $this->recepcion), $this->datosDe($this->recepcion, ['nombre' => 'X']))->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['username' => 'psaucedo']);
        $this->assertSame('Recepcion', $this->recepcion->fresh()->nombre);

        $this->actingAs($this->admin->fresh())->get(route('usuarios.index'))->assertOk();
    }

    public function test_ninguna_cuenta_se_puede_borrar_desde_aqui(): void
    {
        $this->assertFalse(Route::has('usuarios.destroy'));

        $this->actingAs($this->admin->fresh())->delete('/usuarios/' . $this->recepcion->id)->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $this->recepcion->id]);
    }

    public function test_el_menu_del_administrador_enlaza_usuarios_y_recepcion_no_lo_ve(): void
    {
        $menu = fn (User $u) => preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $this->actingAs($u)->get(route('dashboard'))->getContent(), $m) ? $m[1] : '';

        $this->assertStringContainsString('href="' . route('usuarios.index') . '"', $menu($this->admin->fresh()));
        $this->assertStringNotContainsString('Usuarios', $menu($this->recepcion));
    }

    // --- Lista ------------------------------------------------------------------------

    public function test_lista_busca_y_filtra_todas_las_cuentas(): void
    {
        DB::table('recepcionista')->insert(['id_recepcionista' => $this->recepcion->id]);
        DB::table('administrador')->insert(['id_administrador' => $this->admin->id]);
        $inactiva = $this->cuenta('dormida', null, User::ESTADO_INACTIVO);

        $html = $this->actingAs($this->admin->fresh())->get(route('usuarios.index'))->assertOk()
            ->assertSee('admin@ejemplo.test')->assertSee('recepcion@ejemplo.test')->assertSee('medico01')->assertSee('paciente01')
            ->assertSee('Administrador')->assertSee('Recepcionista')->assertSee('Médico')->assertSee('Paciente')->assertSee('Sin perfil')
            ->getContent();
        $this->sinJavascript($html);

        $listar = fn (array $f) => $this->actingAs($this->admin->fresh())->get(route('usuarios.index', $f))->assertOk();
        $listar(['perfil' => 'Paciente'])->assertSee('paciente01@ejemplo.test')->assertDontSee('recepcion@ejemplo.test');
        $listar(['perfil' => 'Médico'])->assertSee('medico01@ejemplo.test')->assertDontSee('paciente01@ejemplo.test');
        $listar(['perfil' => 'Recepcionista'])->assertSee('recepcion@ejemplo.test')->assertDontSee('paciente01@ejemplo.test');
        // También por rol, sin fila en la tabla de ficha.
        $porRol = $this->cuenta('solo_rol', 'Recepcionista');
        $listar(['perfil' => 'Recepcionista'])->assertSee($porRol->email);
        $listar(['estado' => 'Inactivo'])->assertSee($inactiva->email)->assertDontSee('paciente01@ejemplo.test');
        $listar(['q' => 'medico01'])->assertSee('medico01@ejemplo.test')->assertDontSee('paciente01@ejemplo.test');
        $listar(['q' => 'zzz'])->assertSee('No hay cuentas con esos filtros.');
        $listar(['perfil' => 'x', 'estado' => 'y'])->assertSee('paciente01@ejemplo.test');
    }

    public function test_pagina_de_quince_en_quince(): void
    {
        foreach (range(1, 12) as $n) {
            $this->cuenta("extra{$n}");
        }

        $this->actingAs($this->admin->fresh())->get(route('usuarios.index'))->assertSee('Mostrando 1–15 de 16 cuentas');
        $this->actingAs($this->admin->fresh())->get(route('usuarios.index', ['page' => 2]))->assertSee('Mostrando 16–16 de 16 cuentas');
    }

    // --- Crear --------------------------------------------------------------------------

    public function test_crea_un_recepcionista_que_inicia_sesion_con_sus_permisos(): void
    {
        $this->actingAs($this->admin->fresh())->post(route('usuarios.store'), $this->datosNuevo())
            ->assertSessionHasNoErrors()->assertRedirect(route('usuarios.index'))->assertSessionHas('status', 'Cuenta creada correctamente.');

        $nueva = User::where('username', 'psaucedo')->firstOrFail();
        $this->assertTrue(Hash::check('Clave-Nueva-2026', $nueva->password));
        $this->assertNotSame('Clave-Nueva-2026', $nueva->password);
        $this->assertSame([User::ESTADO_ACTIVO, 'Recepcionista', true], [$nueva->estado, $nueva->etiquetaPerfil(), $nueva->esRecepcionista()]);
        $this->assertDatabaseHas('recepcionista', ['id_recepcionista' => $nueva->id]);

        $evento = Bitacora::where('modulo', 'Usuarios')->sole();
        $this->assertSame(['Creación', (int) $nueva->id, $this->admin->id], [$evento->accion, (int) $evento->id_registro, (int) $evento->id_usuario]);
        $this->assertStringNotContainsString('Clave-Nueva-2026', (string) $evento->detalle);

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'paola@ejemplo.test', 'password' => 'Clave-Nueva-2026'])->assertSessionHasNoErrors();
        $this->get(route('citas.index'))->assertOk();
        $this->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_crea_un_administrador(): void
    {
        $this->actingAs($this->admin->fresh())->post(route('usuarios.store'), $this->datosNuevo(['tipo' => 'Administrador']))->assertSessionHasNoErrors();

        $nueva = User::where('username', 'psaucedo')->firstOrFail();
        $this->assertTrue($nueva->esAdministrador());
        $this->assertDatabaseHas('administrador', ['id_administrador' => $nueva->id]);
        $this->actingAs($nueva)->get(route('usuarios.index'))->assertOk();
    }

    public function test_valida_con_mensajes_en_espanol_y_no_crea_nada(): void
    {
        $crear = fn (array $c) => $this->actingAs($this->admin->fresh())->post(route('usuarios.store'), $this->datosNuevo($c));
        $total = User::count();

        $crear(['nombre' => ''])->assertSessionHasErrors(['nombre' => 'El campo nombre es obligatorio.']);
        $crear(['tipo' => 'Paciente'])->assertSessionHasErrors(['tipo' => 'El valor seleccionado en tipo de cuenta no es válido.']);
        $crear(['tipo' => 'Médico'])->assertSessionHasErrors('tipo');
        $crear(['email' => 'recepcion@ejemplo.test'])->assertSessionHasErrors(['email' => 'Ya existe una cuenta con ese correo electrónico.']);
        $crear(['email' => 'Paola@Ejemplo.test'])->assertSessionHasErrors(['email' => 'Escribe el correo electrónico en minúsculas.']);
        $crear(['username' => 'recepcion'])->assertSessionHasErrors(['username' => 'Ya existe una cuenta con ese nombre de usuario.']);
        $crear(['username' => 'ADMIN'])->assertSessionHasErrors(['username' => 'Ese nombre de usuario está reservado.']);
        $crear(['username' => 'a b'])->assertSessionHasErrors(['username' => 'El nombre de usuario solo puede contener letras, números, guiones y guiones bajos.']);
        $crear(['password' => 'corta', 'password_confirmation' => 'corta'])->assertSessionHasErrors(['password' => 'El campo contraseña debe tener al menos 8 caracteres.']);
        $crear(['password_confirmation' => 'distinta-2026'])->assertSessionHasErrors(['password' => 'La confirmación de la contraseña no coincide.']);
        $crear(['telefono' => 'abc'])->assertSessionHasErrors('telefono');

        $this->assertSame($total, User::count());
        $this->assertSame(0, Bitacora::count());
    }

    public function test_si_falla_algo_no_queda_una_cuenta_incompleta(): void
    {
        DB::table('rol')->where('nombre', 'Recepcionista')->update(['nombre' => 'Otro nombre']);

        $this->actingAs($this->admin->fresh())->post(route('usuarios.store'), $this->datosNuevo())
            ->assertSessionHasErrors(['tipo' => 'No existe el rol «Recepcionista»: no se creó la cuenta.']);

        $this->assertDatabaseMissing('users', ['username' => 'psaucedo']);
    }

    // --- Editar -------------------------------------------------------------------------

    public function test_edita_y_desactiva_a_un_recepcionista(): void
    {
        $hash = $this->recepcion->fresh()->password;

        $this->actingAs($this->admin->fresh())->get(route('usuarios.edit', $this->recepcion))->assertOk()
            ->assertSee('value="Recepcion"', false)->assertSee('name="estado"', false)
            ->assertDontSee('name="password"', false)->assertDontSee('name="username"', false);

        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $this->recepcion), $this->datosDe($this->recepcion, [
            'nombre' => 'Carla', 'email' => 'carla@ejemplo.test', 'estado' => 'Inactivo', 'username' => 'otro', 'password' => 'Otra-2026-clave',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', 'Cuenta actualizada correctamente.');

        $fresca = $this->recepcion->fresh();
        $this->assertSame(['Carla', 'carla@ejemplo.test', 'Inactivo', 'recepcion', $hash], [$fresca->nombre, $fresca->email, $fresca->estado, $fresca->username, $fresca->password]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'Modificación', 'modulo' => 'Usuarios', 'id_registro' => $this->recepcion->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'Cambio de estado', 'modulo' => 'Usuarios', 'detalle' => 'Cuenta «recepcion»: Activo → Inactivo.']);

        // Desactivada, pierde el acceso.
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'carla@ejemplo.test', 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_no_cambia_el_estado_propio_ni_el_de_la_cuenta_principal(): void
    {
        $otroAdmin = $this->cuenta('jefa', 'Administrador');

        $this->actingAs($otroAdmin)->get(route('usuarios.edit', $otroAdmin))->assertOk()->assertDontSee('name="estado"', false);
        $this->actingAs($otroAdmin)->put(route('usuarios.update', $otroAdmin), $this->datosDe($otroAdmin, ['estado' => 'Inactivo']))
            ->assertSessionHasErrors(['estado' => 'No puedes cambiar el estado de tu propia cuenta.']);

        $this->actingAs($otroAdmin)->get(route('usuarios.edit', $this->admin))->assertOk()->assertDontSee('name="estado"', false);
        $this->actingAs($otroAdmin)->put(route('usuarios.update', $this->admin), $this->datosDe($this->admin, ['estado' => 'Inactivo']))
            ->assertSessionHasErrors(['estado' => 'La cuenta administrativa principal no se puede desactivar.']);

        $this->assertSame([User::ESTADO_ACTIVO, User::ESTADO_ACTIVO], [$otroAdmin->fresh()->estado, $this->admin->fresh()->estado]);
    }

    public function test_pacientes_y_medicos_se_editan_en_su_modulo(): void
    {
        $admin = $this->admin->fresh();

        $this->actingAs($admin)->get(route('usuarios.edit', $this->paciente))->assertRedirect(route('pacientes.edit', $this->paciente->id));
        $this->actingAs($admin)->get(route('usuarios.edit', $this->medico))->assertRedirect(route('medicos.edit', $this->medico->id));

        // Por aquí no se les cambian datos ni estado.
        $this->actingAs($admin)->put(route('usuarios.update', $this->paciente), $this->datosDe($this->paciente, ['nombre' => 'X', 'estado' => 'Inactivo']))
            ->assertRedirect(route('pacientes.edit', $this->paciente->id));
        $fresca = $this->paciente->fresh();
        $this->assertSame(['Paciente01', 'Activo'], [$fresca->nombre, $fresca->estado]);
    }

    public function test_guardar_sin_cambios_no_genera_registro(): void
    {
        $fresca = $this->recepcion->fresh();

        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $fresca), $this->datosDe($fresca))->assertSessionHasNoErrors();

        $this->assertSame(0, Bitacora::where('modulo', 'Usuarios')->count());
    }
}
