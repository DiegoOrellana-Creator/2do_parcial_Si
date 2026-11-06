<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\EscenarioClinica;
use Tests\TestCase;

/**
 * «Mi perfil»: solo el administrador cambia sus propios datos. Los demás los consultan: los del
 * paciente se cambian en el módulo Pacientes y los del médico, en el módulo Médicos.
 */
class MiPerfilTest extends TestCase
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

    private function datos(array $cambios = []): array
    {
        return array_merge(['nombre' => 'María', 'apellido' => 'Rojas', 'email' => 'maria.rojas@ejemplo.test'], $cambios);
    }

    public function test_los_invitados_van_al_login(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->patch(route('profile.update'), $this->datos())->assertRedirect(route('login'));
    }

    // --- Administrador -----------------------------------------------------------

    public function test_el_formulario_del_administrador_se_puede_editar(): void
    {
        $html = $this->actingAs($this->admin)->get(route('profile.edit'))
            ->assertOk()->assertSee('Datos de mi cuenta')->assertSee('Guardar')
            ->assertSee('value="Admin"', false)->assertSee('value="Prueba"', false)
            ->assertSee('value="admin@ejemplo.test"', false)
            ->assertDontSee('name="name"', false)->assertDontSee('name="username"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('#<input\s+disabled[^>]*id="nombre"#', $html);
        $this->sinJavascript($html);
    }

    public function test_el_administrador_actualiza_sus_datos_y_queda_en_la_bitacora(): void
    {
        $this->actingAs($this->admin)->patch(route('profile.update'), $this->datos())
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'))->assertSessionHas('status', 'profile-updated');

        $fresca = $this->admin->fresh();
        $this->assertSame(['María', 'Rojas', 'maria.rojas@ejemplo.test'], [$fresca->nombre, $fresca->apellido, $fresca->email]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'Modificación', 'modulo' => 'Cuenta', 'id_registro' => $this->admin->id, 'id_usuario' => $this->admin->id]);
    }

    public function test_se_puede_iniciar_sesion_con_el_correo_nuevo(): void
    {
        $this->actingAs($this->admin)->patch(route('profile.update'), $this->datos())->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'admin@ejemplo.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), ['email' => 'maria.rojas@ejemplo.test', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->admin->fresh());
    }

    public function test_no_cambia_el_usuario_el_estado_ni_la_contrasena(): void
    {
        $hash = $this->admin->fresh()->password;

        $this->actingAs($this->admin)->patch(route('profile.update'), $this->datos([
            'username' => 'otro', 'estado' => 'Inactivo', 'password' => 'Otra-Clave-2026', 'id' => 99,
        ]))->assertSessionHasNoErrors();

        $fresca = $this->admin->fresh();
        $this->assertSame(['admin', 'Activo', $hash], [$fresca->username, $fresca->estado, $fresca->password]);
        $this->assertFalse(Hash::check('Otra-Clave-2026', $fresca->password));
    }

    public function test_solo_modifica_la_cuenta_autenticada(): void
    {
        $this->actingAs($this->admin)->patch(route('profile.update'), $this->datos(['id' => $this->recepcion->id]))->assertSessionHasNoErrors();

        $this->assertSame('Recepcion', $this->recepcion->fresh()->nombre);
        $this->assertSame('María', $this->admin->fresh()->nombre);
    }

    public function test_valida_con_mensajes_en_espanol(): void
    {
        $enviar = fn (array $cambios) => $this->actingAs($this->admin)->patch(route('profile.update'), $this->datos($cambios));

        $enviar(['nombre' => '', 'apellido' => ''])->assertSessionHasErrors([
            'nombre' => 'El campo nombre es obligatorio.',
            'apellido' => 'El campo apellido es obligatorio.',
        ]);
        $enviar(['nombre' => str_repeat('a', 101)])->assertSessionHasErrors(['nombre' => 'El campo nombre no debe superar los 100 caracteres.']);
        $enviar(['email' => ''])->assertSessionHasErrors(['email' => 'El campo correo electrónico es obligatorio.']);
        $enviar(['email' => 'no-es-correo'])->assertSessionHasErrors(['email' => 'El correo electrónico no es válido.']);
        $enviar(['email' => 'Maria@Ejemplo.test'])->assertSessionHasErrors(['email' => 'Escribe el correo electrónico en minúsculas.']);
        $enviar(['email' => 'recepcion@ejemplo.test'])->assertSessionHasErrors(['email' => 'Ya existe una cuenta con ese correo electrónico.']);

        $this->assertSame('admin@ejemplo.test', $this->admin->fresh()->email);
        $this->assertSame(0, Bitacora::count());
    }

    public function test_guardar_sin_cambios_no_genera_un_registro_ni_error(): void
    {
        $this->actingAs($this->admin)->patch(route('profile.update'), [
            'nombre' => $this->admin->nombre, 'apellido' => $this->admin->apellido, 'email' => $this->admin->email,
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'profile-updated');

        $this->assertSame(0, Bitacora::count());
    }

    // --- Los demás perfiles ---------------------------------------------------------

    public function test_los_demas_perfiles_consultan_sus_datos_pero_no_los_cambian(): void
    {
        $casos = [
            [$this->recepcion, 'solicítalo al administrador', '<dd>Recepcion</dd>'],
            [$this->medico, 'solicítalo al administrador', '<dd>Medico01</dd>'],
            [$this->paciente, 'solicítalo en la recepción', '<dd>Paciente01</dd>'],
            [$this->cuenta('nadie'), 'solicítalo al administrador', '<dd>Nadie</dd>'],
        ];

        foreach ($casos as [$cuenta, $aviso, $valor]) {
            $antes = $cuenta->fresh();
            $html = $this->actingAs($cuenta)->get(route('profile.edit'))
                ->assertOk()->assertSee($aviso)->assertSee($valor, false)
                ->assertSee('<dd>' . $cuenta->email . '</dd>', false)
                ->getContent();

            // Sin formulario ni campos de edición de sus datos.
            foreach (['id="nombre"', 'id="apellido"', 'id="email"', 'id="username"', 'Guardar', 'name="_method" value="patch"'] as $edicion) {
                $this->assertStringNotContainsString($edicion, $html, "{$cuenta->username}: {$edicion}");
            }
            $this->assertStringContainsString('action="' . route('password.update') . '"', $html);

            $this->actingAs($cuenta)->patch(route('profile.update'), $this->datos())->assertForbidden();

            $fresca = $cuenta->fresh();
            $this->assertSame([$antes->nombre, $antes->apellido, $antes->email], [$fresca->nombre, $fresca->apellido, $fresca->email]);
        }

        $this->assertSame(0, Bitacora::count());
    }

    public function test_todos_pueden_cambiar_su_contrasena(): void
    {
        foreach ([$this->recepcion, $this->medico, $this->paciente] as $cuenta) {
            $this->actingAs($cuenta)->put(route('password.update'), [
                'current_password' => 'password', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
            ])->assertSessionHasNoErrors();

            $this->assertTrue(Hash::check('Nueva-Clave-2026', $cuenta->fresh()->password));
        }
    }

    public function test_recepcion_y_administracion_cambian_los_datos_del_paciente(): void
    {
        foreach ([$this->recepcion, $this->admin] as $i => $personal) {
            $this->actingAs($personal)->put(route('pacientes.update', $this->paciente->id), $this->datos([
                'nombre' => "Nombre{$i}", 'username' => 'paciente01', 'email' => 'paciente01@ejemplo.test',
            ]))->assertSessionHasNoErrors();

            $this->assertSame("Nombre{$i}", $this->paciente->fresh()->nombre);
        }
    }

    public function test_solo_el_administrador_cambia_los_datos_del_medico(): void
    {
        $datos = $this->datos([
            'nombre' => 'Carla', 'username' => 'medico01', 'email' => 'medico01@ejemplo.test', 'estado' => 'Activo',
            'especialidades' => [\App\Models\Especialidad::first()->id_especialidad],
        ]);

        $this->actingAs($this->recepcion)->put(route('medicos.update', $this->medico->id), $datos)->assertForbidden();
        $this->actingAs($this->medico)->put(route('medicos.update', $this->medico->id), $datos)->assertForbidden();
        $this->assertSame('Medico01', $this->medico->fresh()->nombre);

        $this->actingAs($this->admin)->put(route('medicos.update', $this->medico->id), $datos)->assertSessionHasNoErrors();
        $this->assertSame('Carla', $this->medico->fresh()->nombre);
    }
}
