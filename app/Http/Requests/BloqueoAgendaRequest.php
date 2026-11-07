<?php

namespace App\Http\Requests;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos de un bloqueo de agenda. Los choques con citas y con otros bloqueos se comprueban en
 * BloqueoAgendaController::store, dentro de una transacción con el médico bloqueado.
 */
class BloqueoAgendaRequest extends FormRequest
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
            'id_medico' => [
                'required', 'integer',
                Rule::exists('medico', 'id_medico'),
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    if (DB::table('users')->where('id', $valor)->value('estado') !== User::ESTADO_ACTIVO) {
                        $fallar('Solo se pueden bloquear agendas de médicos activos.');
                    }
                },
            ],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_final' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $fin = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->input('fecha') . ' ' . $this->input('hora_final'));
                if ($fin->isPast()) {
                    $validator->errors()->add('hora_final', 'El bloqueo debe terminar en el futuro.');
                }
            },
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
            'integer' => 'El campo :attribute no es válido.',
            'exists' => 'El :attribute seleccionado no existe.',
            'motivo.max' => 'El motivo no debe superar los :max caracteres.',
            'fecha.date_format' => 'La fecha no es válida.',
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'date_format' => 'La :attribute debe tener el formato HH:MM.',
            'hora_final.after' => 'La hora de fin debe ser posterior a la hora de inicio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id_medico' => 'médico',
            'fecha' => 'fecha',
            'hora_inicio' => 'hora de inicio',
            'hora_final' => 'hora de fin',
            'motivo' => 'motivo',
        ];
    }
}
