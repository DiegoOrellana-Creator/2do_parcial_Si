<?php

namespace App\Http\Requests;

use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del registro y la edición de pacientes.
 * Las longitudes máximas siguen las columnas de `users`, `paciente`,
 * `informacion_medica` y `contacto_emergencia`.
 */
class PacienteRequest extends FormRequest
{
    public const SEXOS = ['Femenino', 'Masculino', 'Otro'];

    public const TIPOS_SANGRE = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

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
        /** @var Paciente|null $paciente */
        $paciente = $this->route('paciente');
        $idUsuario = $paciente?->id_paciente;

        return [
            // Datos personales (tabla users)
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'ci' => ['nullable', 'string', 'max:20', Rule::unique('users', 'ci')->ignore($idUsuario)],
            'fecha_nacimiento' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'sexo' => ['nullable', Rule::in(self::SEXOS)],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'direccion' => ['nullable', 'string', 'max:500'],

            // Cuenta (tabla users)
            'email' => ['required', 'string', 'email', 'max:100', Rule::unique('users', 'email')->ignore($idUsuario)],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($idUsuario)],

            // Ficha (tabla paciente)
            'nro_ficha' => ['nullable', 'string', 'max:50', Rule::unique('paciente', 'nro_ficha')->ignore($idUsuario, 'id_paciente')],

            // Información médica (tabla informacion_medica)
            'tipo_sangre' => ['nullable', Rule::in(self::TIPOS_SANGRE)],
            'alergias' => ['nullable', 'string', 'max:2000'],
            'enfermedades_cronicas' => ['nullable', 'string', 'max:2000'],
            'medicamentos' => ['nullable', 'string', 'max:2000'],
            'antecedentes' => ['nullable', 'string', 'max:2000'],

            // Contacto de emergencia principal (tabla contacto_emergencia)
            'contacto_nombre' => ['nullable', 'string', 'max:100', 'required_with:contacto_parentesco,contacto_telefono'],
            'contacto_parentesco' => ['nullable', 'string', 'max:50'],
            'contacto_telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
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
            'email' => 'El :attribute debe ser una dirección de correo válida.',
            'unique' => 'Ya existe un registro con este :attribute.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'before_or_equal' => 'La :attribute no puede ser posterior a hoy.',
            'after' => 'La :attribute debe ser posterior al 01/01/1900.',
            'in' => 'El valor seleccionado en :attribute no es válido.',
            'regex' => 'El :attribute solo puede contener números, espacios y los signos + - ( ).',
            'alpha_dash' => 'El :attribute solo puede contener letras, números, guiones y guiones bajos.',
            'contacto_nombre.required_with' => 'Indica el nombre del contacto de emergencia si registras su parentesco o teléfono.',
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
            'fecha_nacimiento' => 'fecha de nacimiento',
            'sexo' => 'sexo',
            'telefono' => 'teléfono',
            'direccion' => 'dirección',
            'email' => 'correo electrónico',
            'username' => 'nombre de usuario',
            'nro_ficha' => 'número de ficha',
            'tipo_sangre' => 'tipo de sangre',
            'alergias' => 'alergias',
            'enfermedades_cronicas' => 'enfermedades crónicas',
            'medicamentos' => 'medicamentos',
            'antecedentes' => 'antecedentes',
            'contacto_nombre' => 'nombre del contacto',
            'contacto_parentesco' => 'parentesco',
            'contacto_telefono' => 'teléfono del contacto',
        ];
    }
}
