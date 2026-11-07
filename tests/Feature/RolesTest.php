<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * Roles y permisos: exclusivo del administrador; protege los roles del sistema.
 */
class RolesTest extends TestCase
{
    use EscenarioClinica, RefreshDatabase;

    private User $admin;

    private User $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEscenario();
        // Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal.
        $this->admin = $this->cuenta('admin', 'Administrador');
        $this->recepcion = $this->cuenta('recepcion', 'Recepcionista');
        DB::table('rol')->insert([['nombre' => 'Médico'], ['nombre' => 'Paciente']]);
    }

    private function idPermiso(string $codigo): int
    {
        return (int) Permiso::where('codigo', $codigo)->value('id_permiso');
    }

    private function rol(string $nombre): Rol
    {
        return Rol::where('nombre', $nombre)->firstOrFail();
    }

    private function codigos(Rol $rol): array
    {
        return $rol->permisos()->pluck('codigo')->sort()->values()->all();
    }

    private function crear(array $cambios = [])
    {
        return $this->actingAs($this->admin->fresh())->post(route('roles.store'), array_merge(['nombre' => 'Caja', 'descripcion' => 'Atiende pagos', 'permisos' => [$this->idPermiso('citas.ver')]], $cambios));
    }

    // --- Acceso --------------------------------------------------------------------

    public function test_solo_el_administrador_entra(): void
    {
        $rol = $this->rol('Recepcionista');
        $this->get(route('roles.index'))->assertRedirect(route('login'));

        foreach ([$this->recepcion, $this->medicoCon('medico01', null, null), $this->pacienteDe('paciente01'), $this->cuenta('nadie')] as $otro) {
            $this->actingAs($otro)->get(route('roles.index'))->assertForbidden();
            $this->actingAs($otro)->get(route('roles.create'))->assertForbidden();
            $this->actingAs($otro)->post(route('roles.store'), ['nombre' => 'Atajo'])->assertForbidden();
            $this->actingAs($otro)->get(route('roles.edit', $rol))->assertForbidden();
            $this->actingAs($otro)->put(route('roles.update', $rol), ['permisos' => []])->assertForbidden();
        }
        $this->assertDatabaseMissing('rol', ['nombre' => 'Atajo']);
        $this->assertNotEmpty($this->codigos($this->rol('Recepcionista')));

        $html = $this->actingAs($this->admin->fresh())->get(route('roles.index'))->assertOk()
            ->assertSee('Administrador')->assertSee('Recepcionista')->assertSee('Del sistema')
            ->getContent();
        $this->sinJavascript($html);
    }

    public function test_los_roles_no_se_pueden_borrar(): void
    {
        $this->assertFalse(Route::has('roles.destroy'));
        $this->actingAs($this->admin->fresh())->delete('/roles/' . $this->rol('Recepcionista')->id_rol)->assertStatus(405);
    }

    public function test_el_menu_enlaza_roles_solo_al_administrador(): void
    {
        $menu = fn (User $u) => preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $this->actingAs($u)->get(route('dashboard'))->getContent(), $m) ? $m[1] : '';

        $this->assertStringContainsString('href="' . route('roles.index') . '"', $menu($this->admin->fresh()));
        $this->assertStringNotContainsString('Roles y permisos', $menu($this->recepcion));
    }

    // --- Roles personalizados -----------------------------------------------------------

    public function test_crea_un_rol_con_permisos(): void
    {
        $this->crear()->assertSessionHasNoErrors()->assertRedirect(route('roles.index'))->assertSessionHas('status', 'Rol creado correctamente.');

        $rol = $this->rol('Caja');
        $this->assertSame(['citas.ver'], $this->codigos($rol));
        $this->assertSame('Atiende pagos', $rol->descripcion);
        $evento = Bitacora::where('modulo', 'Roles y permisos')->sole();
        $this->assertSame('Rol «Caja» creado. Permisos añadidos: citas.ver.', $evento->detalle);
    }

    public function test_valida_nombres_y_permisos(): void
    {
        $this->crear(['nombre' => ''])->assertSessionHasErrors(['nombre' => 'El campo nombre es obligatorio.']);
        $this->crear(['nombre' => str_repeat('a', 51)])->assertSessionHasErrors(['nombre' => 'El campo nombre no debe superar los 50 caracteres.']);
        foreach (['administrador', 'RECEPCIONISTA', 'Médico', 'medico', 'paciente'] as $reservado) {
            $this->crear(['nombre' => $reservado])->assertSessionHasErrors(['nombre' => 'Ese nombre de rol está reservado.']);
        }
        $this->crear(['permisos' => [999999]])->assertSessionHasErrors('permisos.0');

        // Un permiso que la aplicación ya no comprueba no se puede conceder.
        $this->crear(['permisos' => [$this->idPermiso('pacientes.eliminar')]])->assertSessionHasErrors('permisos.0');
        $this->assertDatabaseMissing('rol', ['nombre' => 'Caja']);

        $this->crear()->assertSessionHasNoErrors();
        $this->crear(['nombre' => 'CAJA'])->assertSessionHasErrors(['nombre' => 'Ya existe un rol con ese nombre.']);
        $this->assertSame(0, Rol::whereIn('nombre', ['administrador', 'medico'])->count());
    }

    public function test_edita_nombre_y_permisos_de_un_rol_personalizado(): void
    {
        $this->crear();
        $rol = $this->rol('Caja');

        $this->actingAs($this->admin->fresh())->put(route('roles.update', $rol), [
            'nombre' => 'Caja central', 'permisos' => [$this->idPermiso('agenda.ver'), $this->idPermiso('citas.crear')],
        ])->assertSessionHasNoErrors();

        $rol = $rol->fresh();
        $this->assertSame('Caja central', $rol->nombre);
        $this->assertSame(['agenda.ver', 'citas.crear'], $this->codigos($rol));
        $detalle = Bitacora::where('accion', 'Modificación')->where('modulo', 'Roles y permisos')->sole()->detalle;
        $this->assertStringContainsString('Permisos añadidos: agenda.ver, citas.crear.', $detalle);
        $this->assertStringContainsString('Permisos quitados: citas.ver.', $detalle);
    }

    public function test_guardar_sin_cambios_no_genera_registro(): void
    {
        $this->crear();
        $rol = $this->rol('Caja');
        $antes = Bitacora::count();

        $this->actingAs($this->admin->fresh())->put(route('roles.update', $rol), [
            'nombre' => 'Caja', 'descripcion' => 'Atiende pagos', 'permisos' => [$this->idPermiso('citas.ver')],
        ])->assertSessionHasNoErrors();

        $this->assertSame($antes, Bitacora::count());
    }

    // --- Roles del sistema ----------------------------------------------------------------

    public function test_los_permisos_de_administrador_medico_y_paciente_no_cambian(): void
    {
        foreach (['Administrador', 'Médico', 'Paciente'] as $nombre) {
            $rol = $this->rol($nombre);
            $antes = $this->codigos($rol);

            $this->actingAs($this->admin->fresh())->get(route('roles.edit', $rol))->assertOk()
                ->assertSee('disabled', false)->assertDontSee('name="nombre"', false);
            $this->actingAs($this->admin->fresh())->put(route('roles.update', $rol), [
                'nombre' => 'Otro', 'permisos' => [$this->idPermiso('citas.ver')],
            ])->assertSessionHasNoErrors();

            $fresco = $rol->fresh();
            $this->assertSame($nombre, $fresco->nombre);
            $this->assertSame($antes, $this->codigos($fresco));
        }

        // El administrador conserva sus accesos.
        $this->actingAs($this->admin->fresh())->get(route('pacientes.index'))->assertOk();
    }

    public function test_los_permisos_de_recepcion_se_pueden_ajustar_con_efecto_inmediato(): void
    {
        $rol = $this->rol('Recepcionista');
        $this->actingAs($this->recepcion)->get(route('citas.index'))->assertOk();

        $this->actingAs($this->admin->fresh())->put(route('roles.update', $rol), ['permisos' => [$this->idPermiso('pacientes.ver')]])->assertSessionHasNoErrors();

        $this->assertSame('Recepcionista', $rol->fresh()->nombre);
        $this->assertSame(['pacientes.ver'], $this->codigos($rol->fresh()));
        $this->actingAs($this->recepcion->fresh())->get(route('citas.index'))->assertForbidden();
        $this->actingAs($this->recepcion->fresh())->get(route('pacientes.index'))->assertOk();
    }

    public function test_conserva_las_filas_de_permisos_sin_uso(): void
    {
        $rol = $this->rol('Administrador');
        $sinUso = $this->idPermiso('pacientes.eliminar');
        $this->assertDatabaseHas('rol_permiso', ['id_rol' => $rol->id_rol, 'id_permiso' => $sinUso]);

        $this->crear();
        $caja = $this->rol('Caja');
        DB::table('rol_permiso')->insert(['id_rol' => $caja->id_rol, 'id_permiso' => $sinUso]);

        $this->actingAs($this->admin->fresh())->put(route('roles.update', $caja), ['nombre' => 'Caja', 'permisos' => []])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rol_permiso', ['id_rol' => $caja->id_rol, 'id_permiso' => $sinUso]);
        $this->assertSame(1, DB::table('rol_permiso')->where('id_rol', $caja->id_rol)->count());
    }

    // --- Asignar desde Usuarios ------------------------------------------------------------

    public function test_asigna_y_quita_roles_personalizados_desde_usuarios(): void
    {
        $this->crear(['permisos' => [$this->idPermiso('citas.ver')]]);
        $caja = $this->rol('Caja');
        $cuenta = $this->cuenta('cajera');
        $datos = ['nombre' => $cuenta->nombre, 'apellido' => $cuenta->apellido, 'email' => $cuenta->email, 'roles_enviados' => 1];

        $this->actingAs($cuenta)->get(route('citas.index'))->assertForbidden();

        $this->actingAs($this->admin->fresh())->get(route('usuarios.edit', $cuenta))->assertOk()->assertSee('Roles personalizados')->assertSee('Caja');
        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $cuenta), [...$datos, 'roles' => [$caja->id_rol]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $cuenta->id, 'id_rol' => $caja->id_rol, 'estado' => 'Activo']);
        $this->assertDatabaseHas('bitacora', ['modulo' => 'Usuarios', 'detalle' => 'Cuenta «cajera». Roles asignados: Caja.']);
        $this->actingAs($cuenta->fresh())->get(route('citas.index'))->assertOk();
        $this->actingAs($cuenta->fresh())->get(route('usuarios.index'))->assertForbidden();

        // Quitarlo conserva la fila inactiva y retira el acceso; volver a asignarlo la reactiva.
        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $cuenta), $datos)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $cuenta->id, 'id_rol' => $caja->id_rol, 'estado' => 'Inactivo']);
        $this->actingAs($cuenta->fresh())->get(route('citas.index'))->assertForbidden();

        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $cuenta), [...$datos, 'roles' => [$caja->id_rol]])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('usuario_rol')->where('id_usuario', $cuenta->id)->where('id_rol', $caja->id_rol)->count());
        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $cuenta->id, 'id_rol' => $caja->id_rol, 'estado' => 'Activo']);
    }

    public function test_desde_usuarios_no_se_asignan_ni_quitan_roles_del_sistema(): void
    {
        $this->crear();
        $cuenta = $this->cuenta('cajera');
        $datos = ['nombre' => $cuenta->nombre, 'apellido' => $cuenta->apellido, 'email' => $cuenta->email, 'roles_enviados' => 1];

        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $cuenta), [...$datos, 'roles' => [$this->rol('Administrador')->id_rol]])
            ->assertSessionHasErrors(['roles.0' => 'Solo se pueden asignar roles personalizados.']);
        $this->assertFalse($cuenta->fresh()->esAdministrador());

        // Guardar a recepción sin marcar nada no le quita su rol del sistema.
        $r = $this->recepcion->fresh();
        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $r), ['nombre' => $r->nombre, 'apellido' => $r->apellido, 'email' => $r->email, 'roles_enviados' => 1])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $r->id, 'id_rol' => $this->rol('Recepcionista')->id_rol, 'estado' => 'Activo']);
    }

    public function test_sin_roles_enviados_no_cambia_las_asignaciones(): void
    {
        $this->crear();
        $caja = $this->rol('Caja');
        $cuenta = $this->cuenta('cajera');
        DB::table('usuario_rol')->insert(['id_usuario' => $cuenta->id, 'id_rol' => $caja->id_rol]);

        $this->actingAs($this->admin->fresh())->put(route('usuarios.update', $cuenta->fresh()), ['nombre' => 'Nueva', 'apellido' => $cuenta->apellido, 'email' => $cuenta->email])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('usuario_rol', ['id_usuario' => $cuenta->id, 'id_rol' => $caja->id_rol, 'estado' => 'Activo']);
    }
}
