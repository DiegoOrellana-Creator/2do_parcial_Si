<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermisosCitasSeeder;
use Database\Seeders\PermisosEspecialidadesSeeder;
use Database\Seeders\PermisosMedicosSeeder;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Barra lateral: enlaces habilitados, módulos sin acceso y módulos pendientes.
 */
class NavegacionTest extends TestCase
{
    use RefreshDatabase;

    /** Módulos del Ciclo 2: siguen en el menú, desactivados. */
    private const MODULOS_PENDIENTES = [
        'Reportes', 'Usuarios', 'Roles y permisos',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);
    }

    private function usuario(string $username, ?string $rol = null): User
    {
        $usuario = User::create([
            'nombre' => 'Usuario', 'apellido' => 'Prueba', 'email' => "{$username}@ejemplo.test",
            'username' => $username, 'password' => 'password', 'estado' => 'Activo',
        ]);

        if ($rol) {
            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol')]);
        }

        return $usuario;
    }

    /** HTML de la navegación del menú lateral de escritorio (sin el logotipo). */
    private function menu(string $html): string
    {
        $this->assertSame(1, preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $m));

        return $m[1];
    }

    public function test_con_permiso_pacientes_es_un_enlace_y_queda_activo_en_sus_paginas(): void
    {
        $admin = $this->usuario('admin', 'Administrador');

        $menu = $this->menu($this->actingAs($admin)->get(route('pacientes.index'))->assertOk()->getContent());

        $this->assertSame(1, substr_count($menu, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('pacientes.index'), '#') . '"\s*aria-current="page"#', $menu);

        // En las demás páginas del módulo (rutas pacientes.*) también queda marcado.
        $menuAlta = $this->menu($this->actingAs($admin)->get(route('pacientes.create'))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('pacientes.index'), '#') . '"\s*aria-current="page"#', $menuAlta);
    }

    public function test_el_dashboard_queda_activo_en_el_dashboard(): void
    {
        $admin = $this->usuario('admin', 'Administrador');

        $menu = $this->menu($this->actingAs($admin)->get(route('dashboard'))->getContent());

        $this->assertSame(1, substr_count($menu, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('dashboard'), '#') . '"\s*aria-current="page"#', $menu);
    }

    public function test_sin_permiso_pacientes_aparece_desactivado_y_sin_enlace(): void
    {
        $sinRol = $this->usuario('sin_rol');

        $menu = $this->menu($this->actingAs($sinRol)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringNotContainsString(route('pacientes.index'), $menu);
        $this->assertMatchesRegularExpression('#title="No tienes permiso para acceder a este módulo"[^>]*>.*?Pacientes.*?Sin acceso#s', $menu);

        // Mostrar el elemento no concede acceso: la ruta sigue protegida.
        $this->actingAs($sinRol)->get(route('pacientes.index'))->assertForbidden();
    }

    public function test_los_modulos_pendientes_no_enlazan_a_paginas_inexistentes(): void
    {
        $admin = $this->usuario('admin', 'Administrador');

        $menu = $this->menu($this->actingAs($admin)->get(route('dashboard'))->getContent());

        foreach (self::MODULOS_PENDIENTES as $modulo) {
            $this->assertMatchesRegularExpression('#title="Disponible próximamente"[^>]*>.*?' . preg_quote($modulo, '#') . '.*?Pronto#s', $menu, "«{$modulo}» debería estar pendiente");
        }

        // Enlaces reales del menú: los módulos del Ciclo 1 y «Mi perfil».
        preg_match_all('#<a href="([^"]+)"#', $menu, $enlaces);
        $this->assertSame([
            route('dashboard'), route('pacientes.index'), route('medicos.index'), route('especialidades.index'),
            route('horarios.index'), route('disponibilidad.index'), route('citas.index'), route('agenda.index'),
            route('bitacora.index'), route('profile.edit'),
        ], $enlaces[1]);
    }

    public function test_el_menu_movil_tiene_los_mismos_elementos(): void
    {
        $admin = $this->usuario('admin', 'Administrador');
        $html = $this->actingAs($admin)->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('#<div class="mobile-menu__panel">(.*?)</div>\s*</details>#s', $html, $movil));
        foreach (['Dashboard', 'Pacientes', ...self::MODULOS_PENDIENTES] as $modulo) {
            $this->assertStringContainsString($modulo, $movil[1]);
        }
        $this->assertStringContainsString('href="' . route('pacientes.index') . '"', $movil[1]);
    }
}
