<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La clínica no usa la verificación de correo de Breeze: la tabla `users` no tiene
 * `email_verified_at` y User no implementa MustVerifyEmail. Las cuentas entran directamente.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_do_not_need_email_verification(): void
    {
        $user = User::factory()->create();

        $this->assertNotInstanceOf(\Illuminate\Contracts\Auth\MustVerifyEmail::class, $user);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_login_goes_straight_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }
}
