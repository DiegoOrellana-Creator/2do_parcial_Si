<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Solo se autentican las cuentas activas: la condición se añade a la búsqueda del
        // usuario, así que una cuenta inactiva se trata igual que unas credenciales incorrectas.
        $credenciales = [...$this->only('email', 'password'), 'estado' => User::ESTADO_ACTIVO];

        if (! Auth::attempt($credenciales, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // Bitácora: sin guardar lo escrito; solo la cuenta afectada si el correo existe.
            $idCuenta = User::where('email', (string) $this->string('email'))->value('id');
            Auditoria::registrar(Auditoria::INICIO_FALLIDO, Auditoria::SESION, $idCuenta, 'Credenciales no válidas o cuenta inactiva.', $idCuenta);

            // Mensaje genérico: no revela si el correo existe, si la contraseña falló o si la cuenta está inactiva.
            throw ValidationException::withMessages([
                'email' => 'No se pudo iniciar sesión con esos datos. Verifica tu correo y contraseña o contacta con la clínica.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
