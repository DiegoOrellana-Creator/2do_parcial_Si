<?php

namespace Tests\Feature;

use App\Models\Especialidad;
use App\Models\User;
use Database\Seeders\PermisosCitasSeeder;
use Database\Seeders\PermisosEspecialidadesSeeder;
use Database\Seeders\PermisosMedicosSeeder;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menú y acceso según el perfil real (tablas paciente, medico, administrador,
 * recepcionista y usuario_rol), sin depender de que existan roles.
 */
class PerfilesMenuTest extends TestCase
{
    use RefreshDatabase;

    private const MENU_PACIENTE = ['Inicio', 'Disponibilidad médica', 'Solicitar cita', 'Mis citas', 'Mi perfil', 'Cerrar sesión'];

    private const MENU_MEDICO = ['Inicio', 'Mi agenda', 'Mis citas', 'Mi perfil', 'Cerrar sesión'];

    private const MENU_PERSONAL = [
        'Dashboard', 'Pacientes', 'Médicos', 'Especialidades', 'Horarios de atención', 'Disponibilidad de citas',
        'Gestión de citas', 'Agenda médica', 'Reportes', 'Usuarios', 'Roles y permisos', 'Bitácora',
        'Mi perfil', 'Cerrar sesión',
    ];

    private const MENU_RECEPCION = [
        'Panel principal', 'Pacientes', 'Citas médicas', 'Agenda', 'Médicos', 'Especialidades', 'Mi perfil', 'Cerrar sesión',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        // Como en clinica_db: cuenta principal id 1 / «admin», sin roles ni filas de perfil.
        $this->admin = $this->usuario('admin');
    }

    private function usuario(string $username): User
    {
        return User::create([
            'nombre' => ucfirst($username), 'apellido' => 'Prueba', 'email' => "{$username}@ejemplo.test",
            'username' => $username, 'password' => 'password', 'estado' => 'Activo',
        ]);
    }

    private function paciente(string $username = 'paciente01'): User
    {
        $u = $this->usuario($username);
        DB::table('paciente')->insert(['id_paciente' => $u->id, 'nro_ficha' => 'HC-' . $u->id, 'inasistencia' => 0, 'bloqueado' => false]);

        return $u;
    }

    private function medico(string $username = 'medico01'): User
    {
        $u = $this->usuario($username);
        DB::table('medico')->insert(['id_medico' => $u->id, 'matricula' => 'MAT-' . $u->id]);

        return $u;
    }

    /** @return array{0: list<string>, 1: list<string>, 2: string} [etiquetas, enlaces, html del menú] */
    private function menu(User $usuario, string $ruta = 'dashboard'): array
    {
        $html = $this->actingAs($usuario)->get(route($ruta))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $m));
        preg_match_all('#<span class="nav-link__label">\s*(.*?)\s*</span>#s', $m[1], $etiquetas);
        preg_match_all('#<a href="([^"]+)"#', $m[1], $enlaces);

        return [$etiquetas[1], $enlaces[1], $m[1]];
    }

    // --- Regresión: los cuatro perfiles con inicio de sesión real ------------

    public function test_los_cuatro_perfiles_inician_sesion_y_su_menu_funciona(): void
    {
        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);
        DB::table('usuario_rol')->insert(['id_usuario' => $this->admin->id, 'id_rol' => DB::table('rol')->where('nombre', 'Administrador')->value('id_rol')]);
        $recepcion = $this->usuario('recepcion01');
        DB::table('recepcionista')->insert(['id_recepcionista' => $recepcion->id]);
        DB::table('usuario_rol')->insert(['id_usuario' => $recepcion->id, 'id_rol' => DB::table('rol')->where('nombre', 'Recepcionista')->value('id_rol')]);
        // Como en clinica_db: roles de perfil Médico y Paciente, sin permisos.
        DB::table('rol')->insert([['nombre' => 'Médico'], ['nombre' => 'Paciente']]);
        $medico = $this->medico();
        $paciente = $this->paciente();
        DB::table('usuario_rol')->insert([
            ['id_usuario' => $medico->id, 'id_rol' => DB::table('rol')->where('nombre', 'Médico')->value('id_rol')],
            ['id_usuario' => $paciente->id, 'id_rol' => DB::table('rol')->where('nombre', 'Paciente')->value('id_rol')],
        ]);

        $casos = [
            [$this->admin, self::MENU_PERSONAL, ['pacientes.index', 'medicos.create', 'horarios.create', 'citas.create'], []],
            [$recepcion, self::MENU_RECEPCION, ['pacientes.index', 'citas.create', 'agenda.index'], ['medicos.create', 'horarios.create']],
            [$medico, self::MENU_MEDICO, ['agenda.index'], ['pacientes.index', 'citas.index', 'medicos.index', 'disponibilidad.index']],
            [$paciente, self::MENU_PACIENTE, [], ['pacientes.index', 'citas.index', 'agenda.index', 'disponibilidad.index', 'medicos.index']],
        ];

        foreach ($casos as [$usuario, $menuEsperado, $permitidas, $prohibidas]) {
            $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password'])
                ->assertRedirect(route('dashboard', absolute: false));
            $this->assertAuthenticatedAs($usuario);

            $html = $this->get(route('dashboard'))->assertOk()->getContent();
            preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $escritorio);
            preg_match('#<details class="mobile-menu">.*?(<nav class="menu".*?</nav>)#s', $html, $movil);
            preg_match_all('#<span class="nav-link__label">\s*(.*?)\s*</span>#s', $escritorio[1], $etiquetas);
            preg_match_all('#<a href="([^"]+)"#', $escritorio[1], $enlaces);
            preg_match_all('#<a href="([^"]+)"#', $movil[1], $enlacesMovil);

            $this->assertSame($menuEsperado, $etiquetas[1], $usuario->username);
            $this->assertSame($enlaces[1], $enlacesMovil[1], "Menú móvil de {$usuario->username}");
            $this->assertContains(route('profile.edit'), $enlaces[1], $usuario->username);
            $this->assertStringContainsString('action="' . route('logout') . '"', $escritorio[1]);

            // Cada enlace activo del menú abre su página.
            foreach ($enlaces[1] as $enlace) {
                $this->get($enlace)->assertOk();
            }
            foreach ($permitidas as $ruta) {
                $this->get(route($ruta))->assertOk();
            }
            foreach ($prohibidas as $ruta) {
                $this->get(route($ruta))->assertForbidden();
            }

            $this->post(route('logout'))->assertRedirect();
            $this->assertGuest();
        }
    }

    // --- Determinación del perfil ------------------------------------------

    public function test_el_perfil_se_determina_por_las_tablas_reales(): void
    {
        $recepcionSubtipo = $this->usuario('recepcion_tabla');
        DB::table('recepcionista')->insert(['id_recepcionista' => $recepcionSubtipo->id]);
        $ambos = $this->medico('ambos');
        DB::table('paciente')->insert(['id_paciente' => $ambos->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->assertSame(User::PERFIL_PERSONAL, $this->admin->perfil(), 'Cuenta principal');
        $this->assertSame(User::PERFIL_PERSONAL, $recepcionSubtipo->perfil(), 'Fila en recepcionista');
        $this->assertSame(User::PERFIL_PACIENTE, $this->paciente()->perfil());
        $this->assertSame(User::PERFIL_MEDICO, $this->medico()->perfil());
        $this->assertSame(User::PERFIL_MEDICO, $ambos->perfil(), 'Paciente y médico: prevalece médico');
        $this->assertSame(User::PERFIL_SIN_PERFIL, $this->usuario('nadie')->perfil());
    }

    public function test_los_roles_paciente_y_medico_no_convierten_a_nadie_en_personal(): void
    {
        $this->seed(PermisosPacientesSeeder::class);
        DB::table('rol')->insert([['nombre' => 'Paciente'], ['nombre' => 'Médico']]);
        $paciente = $this->paciente();
        DB::table('usuario_rol')->insert(['id_usuario' => $paciente->id, 'id_rol' => DB::table('rol')->where('nombre', 'Paciente')->value('id_rol')]);
        $recepcion = $this->usuario('recepcion_rol');
        DB::table('usuario_rol')->insert(['id_usuario' => $recepcion->id, 'id_rol' => DB::table('rol')->where('nombre', 'Recepcionista')->value('id_rol')]);

        $this->assertSame(User::PERFIL_PACIENTE, $paciente->perfil());
        $this->assertSame(User::PERFIL_PERSONAL, $recepcion->perfil());
    }

    public function test_un_rol_sin_permisos_con_otro_nombre_no_muestra_el_menu_administrativo(): void
    {
        // Caso real en clinica_db: el rol se guardó como «MÃ©dico» (acentos mal codificados),
        // así que no coincidía con «Médico» y el médico veía el menú del personal.
        $idRol = DB::table('rol')->insertGetId(['nombre' => 'MÃ©dico'], 'id_rol');
        $medico = $this->medico('medico_rol_mal_escrito');
        DB::table('usuario_rol')->insert(['id_usuario' => $medico->id, 'id_rol' => $idRol]);
        $sinFicha = $this->usuario('rol_sin_permisos');
        DB::table('usuario_rol')->insert(['id_usuario' => $sinFicha->id, 'id_rol' => $idRol]);

        $this->assertSame(User::PERFIL_MEDICO, $medico->perfil());
        $this->assertSame(self::MENU_MEDICO, $this->menu($medico)[0]);
        $this->assertSame(User::PERFIL_SIN_PERFIL, $sinFicha->perfil());
        $this->actingAs($medico)->get(route('pacientes.index'))->assertForbidden();
        $this->actingAs($medico)->get(route('agenda.index'))->assertOk()->assertSee('Mi agenda');
    }

    // --- Menús -------------------------------------------------------------

    public function test_menu_del_paciente(): void
    {
        [$etiquetas, $enlaces, $html] = $this->menu($this->paciente());

        $this->assertSame(self::MENU_PACIENTE, $etiquetas);
        $this->assertSame([
            route('dashboard'), route('mis-citas.disponibilidad'), route('mis-citas.create'), route('mis-citas.index'), route('profile.edit'),
        ], $enlaces);
        $this->assertStringNotContainsString('Pronto', $html, 'Disponibilidad, Solicitar cita y Mis citas ya están habilitadas');
        $this->assertStringContainsString('action="' . route('logout') . '"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_menu_del_medico(): void
    {
        [$etiquetas, $enlaces, $html] = $this->menu($this->medico());

        $this->assertSame(self::MENU_MEDICO, $etiquetas);
        $this->assertSame([route('dashboard'), route('agenda.index'), route('profile.edit')], $enlaces);
        $this->assertSame(1, substr_count($html, 'Pronto'), 'Solo «Mis citas» sigue pendiente');
    }

    public function test_quien_es_paciente_y_medico_ve_el_menu_de_medico(): void
    {
        $ambos = $this->medico('ambos');
        DB::table('paciente')->insert(['id_paciente' => $ambos->id, 'inasistencia' => 0, 'bloqueado' => false]);

        $this->assertSame(self::MENU_MEDICO, $this->menu($ambos)[0]);
    }

    public function test_una_cuenta_sin_perfil_solo_ve_lo_propio(): void
    {
        $this->assertSame(['Inicio', 'Mi perfil', 'Cerrar sesión'], $this->menu($this->usuario('nadie'))[0]);
    }

    public function test_el_menu_del_administrador_no_cambia(): void
    {
        [$etiquetas, $enlaces] = $this->menu($this->admin);

        $this->assertSame(self::MENU_PERSONAL, $etiquetas);
        // Sin permisos cargados, solo Dashboard y la Bitácora (exclusiva del administrador, no depende de permisos).
        $this->assertSame([route('dashboard'), route('bitacora.index'), route('profile.edit')], $enlaces);
    }

    public function test_la_recepcionista_tiene_su_propio_menu_sin_opciones_administrativas(): void
    {
        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);
        $porRol = $this->usuario('recepcion');
        DB::table('usuario_rol')->insert(['id_usuario' => $porRol->id, 'id_rol' => DB::table('rol')->where('nombre', 'Recepcionista')->value('id_rol')]);
        $porFicha = $this->usuario('recepcion_ficha');
        DB::table('recepcionista')->insert(['id_recepcionista' => $porFicha->id]);
        DB::table('usuario_rol')->insert(['id_usuario' => $porFicha->id, 'id_rol' => DB::table('rol')->where('nombre', 'Recepcionista')->value('id_rol')]);

        foreach ([$porRol, $porFicha] as $recepcion) {
            [$etiquetas, $enlaces, $html] = $this->menu($recepcion);

            $this->assertSame(self::MENU_RECEPCION, $etiquetas);
            $this->assertSame([
                route('dashboard'), route('pacientes.index'), route('citas.index'), route('agenda.index'),
                route('medicos.index'), route('especialidades.index'), route('profile.edit'),
            ], $enlaces);
            foreach (['Bitácora', 'Usuarios', 'Roles y permisos', 'Reportes'] as $administrativa) {
                $this->assertStringNotContainsString($administrativa, $html);
            }
        }

        // El menú no sustituye la autorización: lo no permitido sigue dando 403 por URL.
        $this->actingAs($porRol)->get(route('medicos.create'))->assertForbidden();
        $this->actingAs($porRol)->get(route('horarios.create'))->assertForbidden();
        $this->actingAs($porRol)->get(route('citas.create'))->assertOk();
    }

    public function test_quien_es_administrador_y_recepcionista_ve_el_menu_administrativo(): void
    {
        $this->seed([PermisosPacientesSeeder::class, PermisosCitasSeeder::class]);
        $ambos = $this->usuario('ambos');
        foreach (['Administrador', 'Recepcionista'] as $rol) {
            DB::table('usuario_rol')->insert(['id_usuario' => $ambos->id, 'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol')]);
        }

        $this->assertFalse($ambos->esRecepcionista());
        $this->assertSame(self::MENU_PERSONAL, $this->menu($ambos)[0]);
    }

    public function test_el_menu_movil_coincide_con_el_de_escritorio(): void
    {
        $html = $this->actingAs($this->paciente())->get(route('dashboard'))->getContent();
        $this->assertSame(1, preg_match('#<div class="mobile-menu__panel">(.*?)</div>\s*</details>#s', $html, $movil));
        preg_match_all('#<span class="nav-link__label">\s*(.*?)\s*</span>#s', $movil[1], $etiquetas);

        $this->assertSame(self::MENU_PACIENTE, $etiquetas[1]);
    }

    public function test_mi_perfil_queda_activo_en_su_pagina(): void
    {
        [, , $html] = $this->menu($this->paciente(), 'profile.edit');

        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('profile.edit'), '#') . '"\s*aria-current="page"#', $html);
    }

    public function test_cerrar_sesion_desde_el_menu(): void
    {
        $paciente = $this->paciente();

        $this->actingAs($paciente)->post(route('logout'))->assertRedirect('/login');
        $this->assertGuest();
    }

    // --- Inicio ------------------------------------------------------------

    public function test_el_inicio_del_paciente_y_del_medico_solo_muestra_datos_propios(): void
    {
        $paciente = $this->paciente();
        $medico = $this->medico();
        $especialidad = Especialidad::create(['nombre' => 'Cardiología', 'estado' => 'Activo']);
        DB::table('especialidad_medico')->insert(['id_medico' => $medico->id, 'id_especialidad' => $especialidad->id_especialidad]);

        $this->actingAs($paciente)->get(route('dashboard'))->assertOk()
            ->assertSee('Hola, Paciente01')->assertSee('HC-' . $paciente->id)->assertSee('Solicitar cita')
            ->assertDontSee('Datos de demostración')->assertDontSee('Próximas citas')->assertDontSee('Medico01')
            ->assertDontSee('busqueda-global', false);

        $this->actingAs($medico)->get(route('dashboard'))->assertOk()
            ->assertSee('Hola, Medico01')->assertSee('MAT-' . $medico->id)->assertSee('Cardiología')
            ->assertDontSee('Datos de demostración')->assertDontSee('Paciente01');

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Próximas citas')->assertSee('Pacientes registrados')->assertSee('busqueda-global', false)
            ->assertDontSee('Datos de demostración');
    }

    // --- Protección de rutas ------------------------------------------------

    public function test_pacientes_medicos_y_cuentas_sin_perfil_no_entran_a_la_gestion_por_url(): void
    {
        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);
        $paciente = $this->paciente();
        $medico = $this->medico();
        $nadie = $this->usuario('nadie');

        // Aunque por error se les asignara un rol con permisos administrativos, el perfil lo impide.
        $idRol = DB::table('rol')->insertGetId(['nombre' => 'Paciente'], 'id_rol');
        foreach (DB::table('permiso')->pluck('id_permiso') as $idPermiso) {
            DB::table('rol_permiso')->insert(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
        }
        DB::table('usuario_rol')->insert(['id_usuario' => $paciente->id, 'id_rol' => $idRol]);
        $this->assertTrue($paciente->fresh()->tienePermiso('pacientes.ver'));

        $rutas = [
            ['get', route('pacientes.index')], ['get', route('pacientes.create')], ['get', route('pacientes.show', $paciente->id)],
            ['get', route('medicos.index')], ['get', route('medicos.show', $medico->id)], ['get', route('medicos.edit', $medico->id)],
            ['get', route('especialidades.index')], ['post', route('especialidades.store')],
            ['delete', route('pacientes.destroy', $paciente->id)], ['delete', route('medicos.destroy', $medico->id)],
        ];

        foreach ([$paciente, $medico, $nadie] as $usuario) {
            foreach ($rutas as [$metodo, $url]) {
                $this->actingAs($usuario->fresh())->{$metodo}($url)->assertForbidden();
            }
        }

        $this->assertDatabaseHas('paciente', ['id_paciente' => $paciente->id]);
        $this->assertDatabaseHas('medico', ['id_medico' => $medico->id]);
    }

    public function test_el_personal_sigue_entrando_con_sus_permisos(): void
    {
        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);
        $recepcion = $this->usuario('recepcion');
        DB::table('usuario_rol')->insert(['id_usuario' => $recepcion->id, 'id_rol' => DB::table('rol')->where('nombre', 'Recepcionista')->value('id_rol')]);

        $this->actingAs($recepcion)->get(route('pacientes.index'))->assertOk();
        $this->actingAs($recepcion)->get(route('medicos.index'))->assertOk();
        $this->actingAs($recepcion)->get(route('especialidades.index'))->assertOk();
        $this->actingAs($recepcion)->get(route('especialidades.create'))->assertForbidden();
    }

    public function test_las_vistas_por_perfil_no_contienen_javascript(): void
    {
        foreach ([$this->paciente(), $this->medico(), $this->usuario('nadie')] as $usuario) {
            foreach (['dashboard', 'profile.edit'] as $ruta) {
                $html = $this->actingAs($usuario)->get(route($ruta))->assertOk()->getContent();
                foreach (['<script', 'x-data', 'onclick', '@click', 'build/assets'] as $prohibido) {
                    $this->assertStringNotContainsString($prohibido, $html);
                }
            }
        }
    }
}
