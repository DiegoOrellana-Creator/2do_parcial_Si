<?php

namespace App\Http\Requests;

use App\Models\Permiso;
use App\Models\Rol;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Roles: nombre varchar(50), descripcion text. Los nombres de los roles base están reservados.
 */
class RolRequest extends FormRequest
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
        /** @var Rol|null $rol */
        $rol = $this->route('rol');

        return [
            ...($rol?->esBase() ? [] : [
                'nombre' => [
                    'required', 'string', 'max:50',
                    function (string $atributo, mixed $valor, Closure $fallar) use ($rol) {
                        $nombre = mb_strtolower((string) $valor);

                        if (in_array($nombre, array_map('mb_strtolower', [...Rol::BASE, 'Medico']), true)) {
                            $fallar('Ese nombre de rol está reservado.');

                            return;
                        }

                        $repetido = DB::table('rol')->whereRaw('LOWER(nombre) = ?', [$nombre])
                            ->when($rol, fn ($q) => $q->where('id_rol', '<>', $rol->id_rol))
                            ->exists();

                        if ($repetido) {
                            $fallar('Ya existe un rol con ese nombre.');
                        }
                    },
                ],
            ]),
            'descripcion' => ['nullable', 'string', 'max:500'],
            'permisos' => ['sometimes', 'array'],
            'permisos.*' => ['integer', 'distinct', Rule::in(Permiso::query()->enUso()->pluck('id_permiso')->map(fn ($id) => (int) $id)->all())],
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
            'permisos.array' => 'Los permisos seleccionados no son válidos.',
            'permisos.*.integer' => 'Los permisos seleccionados no son válidos.',
            'permisos.*.distinct' => 'Un permiso está repetido.',
            'permisos.*.in' => 'Uno de los permisos seleccionados no es válido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nombre' => 'nombre', 'descripcion' => 'descripción'];
    }
}
