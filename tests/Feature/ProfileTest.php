<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mi perfil (adaptado de Breeze a las reglas de la clínica): solo el administrador cambia sus
 * datos; nadie puede eliminar su cuenta. El detalle está en MiPerfilTest.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /** Como en clinica_db: id 1 / «admin» es la cuenta administrativa principal. */
    private function administrador(): User
    {
        return User::factory()->create(['username' => 'admin']);
    }

    public function test_profile_page_is_displayed(): void
    {
        $this->administrador();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $admin = $this->administrador();

        $this->actingAs($admin)->patch('/profile', [
            'nombre' => 'Ana', 'apellido' => 'Rojas', 'email' => 'ana.rojas@ejemplo.test',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $admin->refresh();
        $this->assertSame(['Ana', 'Rojas', 'ana.rojas@ejemplo.test'], [$admin->nombre, $admin->apellido, $admin->email]);
    }

    public function test_other_profiles_can_not_change_their_information(): void
    {
        $this->administrador();
        $user = User::factory()->create(['nombre' => 'Original']);

        $this->actingAs($user)->patch('/profile', ['nombre' => 'Otro', 'apellido' => 'X', 'email' => $user->email])->assertForbidden();

        $this->assertSame('Original', $user->fresh()->nombre);
    }

    public function test_accounts_can_not_be_deleted(): void
    {
        $this->administrador();
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }
}
