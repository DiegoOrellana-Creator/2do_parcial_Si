<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de prueba con las columnas reales de la tabla `users` (nombre, apellido, username,
 * estado…). La tabla no tiene `name`, `email_verified_at` ni `remember_token`.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->bothify('usuario_####??'),
            'estado' => User::ESTADO_ACTIVO,
            'password' => static::$password ??= Hash::make('password'),
        ];
    }

    /** Cuenta desactivada: no puede iniciar sesión. */
    public function inactiva(): static
    {
        return $this->state(fn (array $attributes) => ['estado' => User::ESTADO_INACTIVO]);
    }
}
