<?php

namespace App\Http\Requests;

use App\Models\HorarioAtencion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación de horarios de atención (tabla horario_atencion).
 * El médico solo se elige al registrar; al editar se conserva.
 */
class HorarioRequest extends FormRequest
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
        $reglas = [
            'dia_semana' => ['required', 'integer', Rule::in(array_keys(HorarioAtencion::DIAS))],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_final' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'duracion_cita' => ['required', 'integer', 'min:5', 'max:240'],
        ];

        if (! $this->route('horario')) {
            $reglas['id_medico'] = [
                'required', 'integer',
                Rule::exists('medico', 'id_medico'),
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    if (DB::table('users')->where('id', $valor)->value('estado') !== User::ESTADO_ACTIVO) {
                        $fallar('Solo se pueden registrar horarios de médicos activos.');
                    }
                },
            ];
        }

        return $reglas;
    }

    /**
     * Reglas que dependen de varios campos: la duración debe caber en el bloque y el bloque
     * no puede solaparse con otro horario del mismo médico ese día.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var HorarioAtencion|null $horario */
                $horario = $this->route('horario');
                $idMedico = $horario?->id_medico ?? (int) $this->input('id_medico');
                $inicio = CarbonImmutable::createFromFormat('H:i', $this->input('hora_inicio'));
                $fin = CarbonImmutable::createFromFormat('H:i', $this->input('hora_final'));

                if ($inicio->diffInMinutes($fin) < (int) $this->input('duracion_cita')) {
                    $validator->errors()->add('duracion_cita', 'La duración de la cita no cabe entre la hora de inicio y la de fin.');

                    return;
                }

                $solapa = HorarioAtencion::where('id_medico', $idMedico)
                    ->where('dia_semana', (int) $this->input('dia_semana'))
                    ->when($horario, fn ($q) => $q->where('id_horario', '<>', $horario->id_horario))
                    ->where('hora_inicio', '<', $fin->format('H:i:s'))
                    ->where('hora_final', '>', $inicio->format('H:i:s'))
                    ->exists();

                if ($solapa) {
                    $validator->errors()->add('hora_inicio', 'El horario se superpone con otro horario del mismo médico ese día.');
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
            'integer' => 'El campo :attribute debe ser un número entero.',
            'in' => 'El valor seleccionado en :attribute no es válido.',
            'exists' => 'El :attribute seleccionado no existe.',
            'date_format' => 'La :attribute debe tener el formato HH:MM.',
            'hora_final.after' => 'La hora de fin debe ser posterior a la hora de inicio.',
            'duracion_cita.min' => 'La duración de la cita debe ser de al menos :min minutos.',
            'duracion_cita.max' => 'La duración de la cita no puede superar los :max minutos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id_medico' => 'médico',
            'dia_semana' => 'día de la semana',
            'hora_inicio' => 'hora de inicio',
            'hora_final' => 'hora de fin',
            'duracion_cita' => 'duración de la cita',
        ];
    }
}
