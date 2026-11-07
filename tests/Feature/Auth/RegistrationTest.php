<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La clínica no tiene registro público: las cuentas las crea el personal (pacientes, médicos y
 * Usuarios). Las rutas de registro de Breeze están desactivadas en routes/auth.php.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_new_users_can_not_register_themselves(): void
    {
        $this->post('/register', [
            'nombre' => 'Test', 'apellido' => 'User', 'email' => 'test@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
