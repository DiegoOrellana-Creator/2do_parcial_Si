<?php

namespace Tests\Feature;

use App\Models\Especialidad;
use App\Models\User;
use Database\Seeders\PermisosEspecialidadesSeeder;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EspecialidadesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class]);
        $this->admin = $this->usuario('admin', 'Administrador');
    }

    private function usuario(string $username, ?string $rol = null): User
    {
        $usuario = User::create([
            'nombre' => 'Usuario', 'apellido' => ucfirst($username), 'email' => "{$username}@ejemplo.test",
            'username' => $username, 'password' => 'password', 'estado' => 'Activo',
        ]);

        if ($rol) {
            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol')]);
        }

        return $usuario;
    }

    private function especialidad(string $nombre, string $estado = 'Activo'): Especialidad
    {
        return Especialidad::create(['nombre' => $nombre, 'descripcion' => "Descripción de {$nombre}", 'estado' => $estado]);
    }

    private function asignarMedico(Especialidad $especialidad, string $username): User
    {
        $medico = $this->usuario($username);
        DB::table('medico')->insert(['id_medico' => $medico->id, 'matricula' => 'MAT-' . $medico->id]);
        DB::table('especialidad_medico')->insert(['id_medico' => $medico->id, 'id_especialidad' => $especialidad->id_especialidad]);

        return $medico;
    }

    // --- Acceso -------------------------------------------------------------

    public function test_un_visitante_es_redirigido_al_login(): void
    {
        $this->get(route('especialidades.index'))->assertRedirect(route('login'));
    }

    public function test_sin_permiso_no_se_accede(): void
    {
        $sinRol = $this->usuario('sin_rol');
        $especialidad = $this->especialidad('Cardiología');

        $this->actingAs($sinRol)->get(route('especialidades.index'))->assertForbidden();
        $this->actingAs($sinRol)->get(route('especialidades.show', $especialidad))->assertForbidden();
        $this->actingAs($sinRol)->post(route('especialidades.store'), ['nombre' => 'X', 'estado' => 'Activo'])->assertForbidden();
        $this->actingAs($sinRol)->delete(route('especialidades.destroy', $especialidad))->assertForbidden();
    }

    public function test_la_recepcionista_solo_consulta(): void
    {
        $recepcion = $this->usuario('recepcion', 'Recepcionista');
        $especialidad = $this->especialidad('Pediatría');

        $this->actingAs($recepcion)->get(route('especialidades.index'))->assertOk()->assertDontSee('Registrar especialidad');
        $this->actingAs($recepcion)->get(route('especialidades.show', $especialidad))->assertOk()->assertDontSee('Editar</a>', false);
        $this->actingAs($recepcion)->get(route('especialidades.create'))->assertForbidden();
        $this->actingAs($recepcion)->put(route('especialidades.update', $especialidad), ['nombre' => 'Otra', 'estado' => 'Activo'])->assertForbidden();
        $this->actingAs($recepcion)->delete(route('especialidades.destroy', $especialidad))->assertForbidden();
    }

    // --- CRUD ---------------------------------------------------------------

    public function test_registra_una_especialidad(): void
    {
        $this->actingAs($this->admin)
            ->post(route('especialidades.store'), ['nombre' => 'Neurología', 'descripcion' => 'Sistema nervioso.', 'estado' => 'Activo'])
            ->assertRedirect(route('especialidades.show', Especialidad::firstWhere('nombre', 'Neurología')))
            ->assertSessionHas('status', 'Especialidad registrada correctamente.');

        $this->assertDatabaseHas('especialidad', ['nombre' => 'Neurología', 'descripcion' => 'Sistema nervioso.', 'estado' => 'Activo']);
    }

    public function test_el_alta_propone_estado_activo(): void
    {
        $this->actingAs($this->admin)->get(route('especialidades.create'))
            ->assertOk()->assertSee('<option value="Activo" selected', false);
    }

    public function test_lista_busca_filtra_y_pagina_con_get(): void
    {
        $this->especialidad('Cardiología');
        $this->especialidad('Dermatología', 'Inactivo');
        $medicinas = collect(range(1, 10))->map(fn ($i) => $this->especialidad("Medicina {$i}"));
        $this->asignarMedico($medicinas[0], 'doctora');

        $this->actingAs($this->admin)->get(route('especialidades.index', ['q' => 'CARDIO']))
            ->assertOk()->assertSee('Cardiología')->assertDontSee('Dermatología');

        $this->actingAs($this->admin)->get(route('especialidades.index', ['estado' => 'Inactivo']))
            ->assertOk()->assertSee('Dermatología')->assertDontSee('Cardiología');

        $this->actingAs($this->admin)->get(route('especialidades.index'))
            ->assertOk()->assertSee('de 12 especialidades')->assertSee('aria-label="Paginación"', false);

        $this->actingAs($this->admin)->get(route('especialidades.index', ['estado' => 'cualquiera']))->assertOk()->assertSee('de 12 especialidades');
    }

    public function test_muestra_el_detalle_con_sus_medicos(): void
    {
        $especialidad = $this->especialidad('Traumatología');
        $this->asignarMedico($especialidad, 'traumatologo');

        $this->actingAs($this->admin)->get(route('especialidades.show', $especialidad))
            ->assertOk()
            ->assertSee('Traumatología')
            ->assertSee('Usuario Traumatologo')
            ->assertSee('Matrícula MAT-');
    }

    public function test_actualiza_y_desactiva_una_especialidad(): void
    {
        $especialidad = $this->especialidad('Oftalmologia');

        $this->actingAs($this->admin)
            ->put(route('especialidades.update', $especialidad), ['nombre' => 'Oftalmología', 'descripcion' => '', 'estado' => 'Inactivo'])
            ->assertRedirect(route('especialidades.show', $especialidad))
            ->assertSessionHas('status', 'Especialidad actualizada correctamente.');

        $this->assertDatabaseHas('especialidad', ['id_especialidad' => $especialidad->id_especialidad, 'nombre' => 'Oftalmología', 'descripcion' => null, 'estado' => 'Inactivo']);
    }

    // --- Validación ---------------------------------------------------------

    public function test_valida_campos_con_mensajes_en_espanol(): void
    {
        $this->especialidad('Pediatría');

        $this->actingAs($this->admin)->from(route('especialidades.create'))
            ->post(route('especialidades.store'), ['nombre' => '', 'descripcion' => str_repeat('a', 1001), 'estado' => 'Suspendida'])
            ->assertRedirect(route('especialidades.create'))
            ->assertSessionHasErrors([
                'nombre' => 'El campo nombre es obligatorio.',
                'descripcion' => 'El campo descripción no debe superar los 1000 caracteres.',
                'estado' => 'El valor seleccionado en estado no es válido.',
            ]);

        $this->actingAs($this->admin)->post(route('especialidades.store'), ['nombre' => str_repeat('n', 101), 'estado' => 'Activo'])
            ->assertSessionHasErrors(['nombre' => 'El campo nombre no debe superar los 100 caracteres.']);

        $this->assertDatabaseCount('especialidad', 1);
    }

    public function test_no_permite_nombres_repetidos_sin_distinguir_mayusculas(): void
    {
        $pediatria = $this->especialidad('Pediatría');
        $this->especialidad('Cardiología');

        $this->actingAs($this->admin)->post(route('especialidades.store'), ['nombre' => 'PEDIATRÍA', 'estado' => 'Activo'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe una especialidad con ese nombre.']);
        $this->actingAs($this->admin)->post(route('especialidades.store'), ['nombre' => '  pediatría ', 'estado' => 'Activo'])
            ->assertSessionHasErrors('nombre');

        // Al editar, conservar su propio nombre es válido; usar el de otra no.
        $this->actingAs($this->admin)->put(route('especialidades.update', $pediatria), ['nombre' => 'Pediatría', 'estado' => 'Activo'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('especialidades.update', $pediatria), ['nombre' => 'cardiología', 'estado' => 'Activo'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe una especialidad con ese nombre.']);
    }

    public function test_el_formulario_muestra_los_errores_junto_a_los_campos(): void
    {
        $this->actingAs($this->admin)->from(route('especialidades.create'))->followingRedirects()
            ->post(route('especialidades.store'), ['nombre' => '', 'estado' => 'Activo'])
            ->assertOk()
            ->assertSee('No se pudo guardar: revisa el campo marcado.')
            ->assertSee('El campo nombre es obligatorio.')
            ->assertSee('aria-invalid="true"', false);
    }

    // --- Eliminación y relación con médicos ---------------------------------

    public function test_elimina_una_especialidad_sin_referencias(): void
    {
        $especialidad = $this->especialidad('Geriatría');

        $this->actingAs($this->admin)->get(route('especialidades.show', $especialidad))->assertSee('Eliminar definitivamente');

        $this->actingAs($this->admin)->delete(route('especialidades.destroy', $especialidad))
            ->assertRedirect(route('especialidades.index'))
            ->assertSessionHas('status', 'Especialidad eliminada correctamente.');

        $this->assertDatabaseMissing('especialidad', ['id_especialidad' => $especialidad->id_especialidad]);
    }

    public function test_no_elimina_una_especialidad_asignada_a_medicos(): void
    {
        $especialidad = $this->especialidad('Cardiología');
        $medico = $this->asignarMedico($especialidad, 'cardiologo');

        $this->actingAs($this->admin)->get(route('especialidades.show', $especialidad))
            ->assertSee('no se puede eliminar porque está asignada a 1 médico')
            ->assertSee('desactivarla')
            ->assertDontSee('Eliminar definitivamente');

        $this->actingAs($this->admin)->from(route('especialidades.show', $especialidad))
            ->delete(route('especialidades.destroy', $especialidad))
            ->assertRedirect(route('especialidades.show', $especialidad))
            ->assertSessionHas('error', 'No se puede eliminar la especialidad porque está asignada a 1 médico. Puedes desactivarla para que deje de ofrecerse.');

        $this->assertDatabaseHas('especialidad', ['id_especialidad' => $especialidad->id_especialidad]);
        $this->assertDatabaseHas('especialidad_medico', ['id_medico' => $medico->id, 'id_especialidad' => $especialidad->id_especialidad]);
    }

    public function test_no_elimina_una_especialidad_con_citas(): void
    {
        $especialidad = $this->especialidad('Neumología');
        $otra = $this->especialidad('Medicina General');
        $medico = $this->asignarMedico($otra, 'neumologo');
        $paciente = $this->usuario('paciente');
        DB::table('paciente')->insert(['id_paciente' => $paciente->id, 'inasistencia' => 0, 'bloqueado' => false]);
        DB::table('citas')->insert([
            'codigo' => 'C-1', 'id_paciente' => $paciente->id, 'id_medico' => $medico->id, 'id_especialidad' => $especialidad->id_especialidad,
            'fecha_cita' => '2026-11-03', 'hora_inicio' => '09:00', 'hora_fin' => '09:30',
        ]);

        $this->actingAs($this->admin)->delete(route('especialidades.destroy', $especialidad))
            ->assertSessionHas('error', 'No se puede eliminar la especialidad porque tiene 1 cita registrada. Puedes desactivarla para que deje de ofrecerse.');

        $this->assertDatabaseHas('citas', ['codigo' => 'C-1']);
    }

    public function test_desactivar_una_especialidad_en_uso_conserva_sus_relaciones(): void
    {
        $especialidad = $this->especialidad('Urología');
        $medico = $this->asignarMedico($especialidad, 'urologo');

        $this->actingAs($this->admin)
            ->put(route('especialidades.update', $especialidad), ['nombre' => 'Urología', 'estado' => 'Inactivo'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('especialidad', ['id_especialidad' => $especialidad->id_especialidad, 'estado' => 'Inactivo']);
        $this->assertDatabaseHas('especialidad_medico', ['id_medico' => $medico->id, 'id_especialidad' => $especialidad->id_especialidad]);
    }

    // --- Vistas -------------------------------------------------------------

    public function test_las_vistas_usan_csrf_y_no_contienen_javascript(): void
    {
        $especialidad = $this->especialidad('Nefrología');

        foreach ([route('especialidades.index'), route('especialidades.create'), route('especialidades.show', $especialidad), route('especialidades.edit', $especialidad)] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            foreach (['<script', 'x-data', 'x-show', 'onclick', '@click', 'build/assets'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $html, "{$url} contiene «{$prohibido}»");
            }
        }

        $this->actingAs($this->admin)->get(route('especialidades.create'))->assertSee('name="_token"', false);
        $this->actingAs($this->admin)->get(route('especialidades.edit', $especialidad))->assertSee('name="_method" value="PUT"', false);
        $this->actingAs($this->admin)->get(route('especialidades.show', $especialidad))->assertSee('name="_method" value="DELETE"', false);
    }

    public function test_el_menu_enlaza_especialidades_y_la_marca_activa(): void
    {
        $html = $this->actingAs($this->admin)->get(route('especialidades.index'))->getContent();
        preg_match('#<aside class="sidebar">.*?(<nav class="menu".*?</nav>)#s', $html, $menu);

        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('especialidades.index'), '#') . '"\s*aria-current="page"#', $menu[1]);
    }
}
