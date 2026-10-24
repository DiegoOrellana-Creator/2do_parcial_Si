<?php

namespace App\Http\Requests;

use App\Models\Especialidad;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validación de especialidades según la tabla `especialidad`:
 * nombre varchar(100) NOT NULL, descripcion text NULL, estado varchar(20) NOT NULL.
 */
class EspecialidadRequest extends FormRequest
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
        /** @var Especialidad|null $especialidad */
        $especialidad = $this->route('especialidad');

        return [
            'nombre' => [
                'required', 'string', 'max:100',
                // La base no tiene un índice único sobre nombre: se evita el duplicado aquí,
                // sin distinguir mayúsculas ni minúsculas.
                function (string $atributo, mixed $valor, Closure $fallar) use ($especialidad) {
                    $repetida = DB::table('especialidad')
                        ->whereRaw('LOWER(nombre) = ?', [mb_strtolower((string) $valor)])
                        ->when($especialidad, fn ($q) => $q->where('id_especialidad', '<>', $especialidad->id_especialidad))
                        ->exists();

                    if ($repetida) {
                        $fallar('Ya existe una especialidad con ese nombre.');
                    }
                },
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'estado' => ['required', Rule::in(Especialidad::ESTADOS)],
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
            'in' => 'El valor seleccionado en :attribute no es válido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'descripcion' => 'descripción',
            'estado' => 'estado',
        ];
    }
}
