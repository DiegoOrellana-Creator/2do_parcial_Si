<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;

/**
 * Reprogramación de una cita: nueva franja y fecha, con las mismas reglas que CitaRequest, y motivo
 * obligatorio. El paciente y la especialidad no se envían: son siempre los de la cita.
 */
class ReprogramacionCitaRequest extends CitaRequest
{
    public function rules(): array
    {
        return [
            ...Arr::only(parent::rules(), ['franja', 'fecha']),
            'motivo_reprogramacion' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'motivo_reprogramacion.required' => 'Indica el motivo de la reprogramación.',
            'motivo_reprogramacion.max' => 'El motivo no debe superar los :max caracteres.',
        ];
    }
}
