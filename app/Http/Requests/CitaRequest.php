<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos básicos para registrar una cita. Las reglas que dependen del estado de la agenda
 * (franja libre, médico activo con la especialidad, paciente sin bloqueo ni otra cita a esa
 * hora) se comprueban en CitaController::store dentro de una transacción con bloqueo.
 */
class CitaRequest extends FormRequest
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
        return [
            'id_paciente' => ['required', 'integer', Rule::exists('paciente', 'id_paciente')],
            'id_especialidad' => ['required', 'integer', Rule::exists('especialidad', 'id_especialidad')],
            'franja' => ['required', 'string', 'regex:/^\d+\|\d{2}:\d{2}$/'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_paciente.required' => 'Selecciona el paciente.',
            'id_paciente.exists' => 'El paciente seleccionado no existe.',
            'id_especialidad.required' => 'Selecciona la especialidad.',
            'id_especialidad.exists' => 'La especialidad seleccionada no existe.',
            'franja.required' => 'Selecciona un médico y una hora disponibles.',
            'franja.regex' => 'La franja seleccionada no es válida.',
            'fecha.required' => 'Indica la fecha de la cita.',
            'fecha.date_format' => 'La fecha no es válida.',
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'motivo.max' => 'El motivo no debe superar los :max caracteres.',
            'integer' => 'El valor seleccionado no es válido.',
            'string' => 'El campo :attribute debe ser texto.',
        ];
    }

    /**
     * Médico y hora elegidos («id_medico|HH:MM»).
     *
     * @return array{0: int, 1: string}
     */
    public function franjaElegida(): array
    {
        [$medico, $hora] = explode('|', $this->validated('franja'));

        return [(int) $medico, $hora];
    }
}
