<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;

/**
 * Solicitud de cita del propio paciente: las mismas reglas que CitaRequest, pero sin
 * `id_paciente`. El paciente es siempre la cuenta autenticada; si el formulario enviara otro
 * identificador, no forma parte de los datos validados y se ignora.
 */
class SolicitudCitaRequest extends CitaRequest
{
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['id_paciente']);
    }
}
