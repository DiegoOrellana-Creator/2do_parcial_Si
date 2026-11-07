<?php

namespace App\Http\Requests;

use App\Http\Controllers\UsuarioController;
use App\Models\Rol;
use App\Models\User;
use App\Rules\UsuarioNoReservado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cuentas del personal. El tipo, el nombre de usuario y la contraseña solo se indican al crearla;
 * el estado solo se cambia al editarla. Longitudes según la tabla `users`.
 */
class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización se aplica con el middleware «can» del controlador.
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $usuario */
        $usuario = $this->route('usuario');
        $id = $usuario?->id;

        return [
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'ci' => ['nullable', 'string', 'max:20', Rule::unique('users', 'ci')->ignore($id)],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:100', Rule::unique('users', 'email')->ignore($id)],

            ...($usuario ? [
                'estado' => ['sometimes', Rule::in(User::ESTADOS)],
                'roles_enviados' => ['sometimes', 'boolean'],
                'roles' => ['sometimes', 'array'],
                'roles.*' => ['integer', 'distinct', Rule::in(Rol::query()->personalizados()->pluck('id_rol')->map(fn ($id) => (int) $id)->all())],
            ] : [
                'tipo' => ['required', Rule::in(array_keys(UsuarioController::TIPOS))],
                'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', new UsuarioNoReservado, Rule::unique('users', 'username')],
                'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute no debe superar los :max caracteres.',
            'min' => 'El campo :attribute debe tener al menos :min caracteres.',
            'unique' => 'Ya existe una cuenta con ese :attribute.',
            'in' => 'El valor seleccionado en :attribute no es válido.',
            'alpha_dash' => 'El :attribute solo puede contener letras, números, guiones y guiones bajos.',
            'email.lowercase' => 'Escribe el correo electrónico en minúsculas.',
            'email.email' => 'El correo electrónico no es válido.',
            'telefono.regex' => 'El teléfono solo puede contener números, espacios y los signos + - ( ).',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'roles.array' => 'Los roles seleccionados no son válidos.',
            'roles.*.integer' => 'Los roles seleccionados no son válidos.',
            'roles.*.distinct' => 'Un rol está repetido.',
            'roles.*.in' => 'Solo se pueden asignar roles personalizados.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'apellido' => 'apellido',
            'ci' => 'carnet de identidad',
            'telefono' => 'teléfono',
            'direccion' => 'dirección',
            'email' => 'correo electrónico',
            'estado' => 'estado',
            'tipo' => 'tipo de cuenta',
            'username' => 'nombre de usuario',
            'password' => 'contraseña',
        ];
    }
}
