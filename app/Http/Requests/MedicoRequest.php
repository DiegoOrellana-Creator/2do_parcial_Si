<?php

namespace App\Http\Requests;

use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validación del registro y la edición de médicos.
 * Longitudes según `users` (datos personales y cuenta), `medico` (matricula y codigo varchar(50),
 * únicos y opcionales) y `especialidad_medico` (especialidades existentes).
 */
class MedicoRequest extends FormRequest
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
        /** @var Medico|null $medico */
        $medico = $this->route('medico');
        $idUsuario = $medico?->id_medico;

        $asignadas = $medico
            ? DB::table('especialidad_medico')->where('id_medico', $idUsuario)->pluck('id_especialidad')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            // Datos personales (tabla users)
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'ci' => ['nullable', 'string', 'max:20', Rule::unique('users', 'ci')->ignore($idUsuario)],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'sexo' => ['nullable', Rule::in(PacienteRequest::SEXOS)],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'direccion' => ['nullable', 'string', 'max:500'],

            // Cuenta (tabla users)
            'email' => ['required', 'string', 'email', 'max:100', Rule::unique('users', 'email')->ignore($idUsuario)],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($idUsuario)],
            'estado' => ['required', Rule::in(User::ESTADOS)],

            // Contraseña: solo al registrar; al editar no se pide ni se cambia.
            ...($medico ? [] : [
                'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            ]),

            // Datos profesionales (tabla medico)
            'matricula' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9.\/\-]+$/', Rule::unique('medico', 'matricula')->ignore($idUsuario, 'id_medico')],
            'codigo' => ['nullable', 'string', 'max:50', 'alpha_dash', Rule::unique('medico', 'codigo')->ignore($idUsuario, 'id_medico')],

            // Especialidades (tabla especialidad_medico)
            'especialidades' => ['required', 'array', 'min:1'],
            'especialidades.*' => [
                'integer', 'distinct',
                Rule::exists('especialidad', 'id_especialidad'),
                // Solo se pueden AÑADIR especialidades activas; una ya asignada que luego se
                // desactivó puede conservarse.
                function (string $atributo, mixed $valor, Closure $fallar) use ($asignadas) {
                    if (in_array((int) $valor, $asignadas, true)) {
                        return;
                    }

                    $estado = DB::table('especialidad')->where('id_especialidad', $valor)->value('estado');
                    if ($estado !== null && $estado !== Especialidad::ACTIVA) {
                        $fallar('No se puede asignar una especialidad inactiva.');
                    }
                },
            ],
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
            'before' => 'La :attribute debe ser anterior a hoy.',
            'after' => 'La :attribute debe ser posterior al 01/01/1900.',
            'in' => 'El valor seleccionado en :attribute no es válido.',
            'alpha_dash' => 'El :attribute solo puede contener letras, números, guiones y guiones bajos.',
            'telefono.regex' => 'El teléfono solo puede contener números, espacios y los signos + - ( ).',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'matricula.regex' => 'La matrícula solo puede contener letras, números y los signos . / -',
            'especialidades.required' => 'Selecciona al menos una especialidad.',
            'especialidades.min' => 'Selecciona al menos una especialidad.',
            'especialidades.array' => 'Las especialidades seleccionadas no son válidas.',
            'especialidades.*.integer' => 'Las especialidades seleccionadas no son válidas.',
            'especialidades.*.distinct' => 'Una especialidad está repetida.',
            'especialidades.*.exists' => 'Una de las especialidades seleccionadas no existe.',
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
            'estado' => 'estado',
            'password' => 'contraseña',
            'matricula' => 'matrícula',
            'codigo' => 'código',
            'especialidades' => 'especialidades',
        ];
    }
}
