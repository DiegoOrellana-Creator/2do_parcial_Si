<?php

namespace App\Http\Controllers;

use App\Models\Especialidad;
use App\Services\Disponibilidad;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Consultar disponibilidad de citas: franjas libres por especialidad, médico y fecha.
 */
class DisponibilidadController extends Controller implements HasMiddleware
{
    public const DIAS_MAXIMOS = 90;

    public static function middleware(): array
    {
        return [new Middleware('can:disponibilidad.ver')];
    }

    public function index(Request $request, Disponibilidad $disponibilidad): View
    {
        $especialidades = Especialidad::where('estado', Especialidad::ACTIVA)->orderBy('nombre')->get();
        $consulta = $this->validarConsulta($request);

        $resultados = null;
        $especialidad = null;

        if ($consulta['valida']) {
            $especialidad = Especialidad::find($consulta['especialidad']);
            $resultados = $disponibilidad->porEspecialidad($especialidad, $consulta['fecha'], $consulta['medico'] ?: null);
        }

        $medicos = $consulta['especialidad'] ? $disponibilidad->medicosActivosDe((int) $consulta['especialidad']) : collect();

        return view('disponibilidad.index', [
            'especialidades' => $especialidades,
            'medicos' => $medicos,
            'consulta' => $consulta,
            'especialidad' => $especialidad,
            'resultados' => $resultados,
        ]);
    }

    /**
     * Valida los parámetros GET de la consulta. Devuelve los valores y los errores para la vista.
     *
     * @return array{valida: bool, especialidad: int, medico: int, fecha: CarbonImmutable, errores: \Illuminate\Support\MessageBag}
     */
    public static function validarConsulta(Request $request): array
    {
        $hoy = CarbonImmutable::today();
        $fechaTexto = (string) $request->query('fecha', $hoy->toDateString());
        $consultado = $request->filled('especialidad');

        $validador = Validator::make(
            ['especialidad' => $request->query('especialidad'), 'medico' => $request->query('medico'), 'fecha' => $fechaTexto],
            [
                'especialidad' => ['required', 'integer', Rule::exists('especialidad', 'id_especialidad')->where('estado', Especialidad::ACTIVA)],
                'medico' => ['nullable', 'integer'],
                'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . $hoy->addDays(self::DIAS_MAXIMOS)->toDateString()],
            ],
            [
                'especialidad.required' => 'Selecciona una especialidad.',
                'especialidad.exists' => 'La especialidad seleccionada no existe o no está activa.',
                'especialidad.integer' => 'La especialidad seleccionada no es válida.',
                'medico.integer' => 'El médico seleccionado no es válido.',
                'fecha.required' => 'Indica una fecha.',
                'fecha.date_format' => 'La fecha no es válida.',
                'fecha.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
                'fecha.before_or_equal' => 'Solo se puede consultar hasta ' . self::DIAS_MAXIMOS . ' días a partir de hoy.',
            ]
        );

        $errores = $consultado ? $validador->errors() : new \Illuminate\Support\MessageBag;
        $fecha = rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d', $fechaTexto)->startOfDay(), $hoy, false) ?: $hoy;

        return [
            'valida' => $consultado && $validador->passes(),
            'especialidad' => (int) $request->query('especialidad', 0),
            'medico' => (int) $request->query('medico', 0),
            'fecha' => $fecha,
            'errores' => $errores,
        ];
    }
}
