<?php

namespace App\Http\Controllers;

use App\Http\Requests\CitaRequest;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HistorialEstadoCita;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\Disponibilidad;
use App\Services\RegistroCita;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Gestión de citas del Ciclo 1: listar, consultar, registrar y cancelar.
 * Confirmar, reprogramar y registrar asistencia corresponden al Ciclo 2.
 */
class CitaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:citas.ver', only: ['index', 'show']),
            new Middleware('can:citas.crear', only: ['create', 'store']),
            new Middleware('can:citas.cancelar', only: ['cancelar']),
        ];
    }

    public function index(Request $request): View
    {
        $filtros = [
            'q' => trim((string) $request->query('q', '')),
            'desde' => $this->fechaValida($request->query('desde')) ?? now()->toDateString(),
            'hasta' => $this->fechaValida($request->query('hasta')),
            'estado' => in_array($request->query('estado'), Cita::ESTADOS, true) ? $request->query('estado') : '',
            'medico' => (int) $request->query('medico', 0),
            'especialidad' => (int) $request->query('especialidad', 0),
        ];

        $citas = Cita::query()
            ->select('citas.*')
            ->join('users as paciente', 'paciente.id', '=', 'citas.id_paciente')
            ->join('paciente as ficha', 'ficha.id_paciente', '=', 'citas.id_paciente')
            ->with(['paciente.usuario', 'medico.usuario', 'especialidad'])
            ->where('citas.fecha_cita', '>=', $filtros['desde'])
            ->when($filtros['hasta'], fn ($q) => $q->where('citas.fecha_cita', '<=', $filtros['hasta']))
            ->when($filtros['estado'], fn ($q) => $q->where('citas.estado', $filtros['estado']))
            ->when($filtros['medico'], fn ($q) => $q->where('citas.id_medico', $filtros['medico']))
            ->when($filtros['especialidad'], fn ($q) => $q->where('citas.id_especialidad', $filtros['especialidad']))
            ->when($filtros['q'] !== '', function ($query) use ($filtros) {
                $termino = '%' . mb_strtolower($filtros['q']) . '%';
                $query->where(function ($q) use ($termino) {
                    $q->whereRaw("LOWER(paciente.nombre || ' ' || paciente.apellido) LIKE ?", [$termino])
                        ->orWhereRaw('LOWER(paciente.ci) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(ficha.nro_ficha) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(citas.codigo) LIKE ?', [$termino]);
                });
            })
            ->orderBy('citas.fecha_cita')
            ->orderBy('citas.hora_inicio')
            ->paginate(15)
            ->withQueryString();

        return view('citas.index', [
            'citas' => $citas,
            'filtros' => $filtros,
            'medicos' => Medico::query()->select('medico.*')->join('users', 'users.id', '=', 'medico.id_medico')->with('usuario')->orderBy('users.apellido')->get(),
            'especialidades' => Especialidad::orderBy('nombre')->get(),
        ]);
    }

    public function create(Request $request, Disponibilidad $disponibilidad): View
    {
        $consulta = DisponibilidadController::validarConsulta($request);
        $especialidad = $consulta['valida'] ? Especialidad::find($consulta['especialidad']) : null;

        return view('citas.create', [
            'especialidades' => Especialidad::where('estado', Especialidad::ACTIVA)->orderBy('nombre')->get(),
            'medicos' => $consulta['especialidad'] ? $disponibilidad->medicosActivosDe($consulta['especialidad']) : collect(),
            'consulta' => $consulta,
            'especialidad' => $especialidad,
            'resultados' => $especialidad ? $disponibilidad->porEspecialidad($especialidad, $consulta['fecha'], $consulta['medico'] ?: null) : null,
            'pacientes' => $especialidad ? $this->pacientesHabilitados() : collect(),
            'idPaciente' => (int) $request->query('paciente', 0),
        ]);
    }

    public function store(CitaRequest $request, RegistroCita $registro): RedirectResponse
    {
        $datos = $request->validated();
        [$idMedico, $hora] = $request->franjaElegida();

        $cita = $registro->registrar(
            (int) $datos['id_paciente'],
            $idMedico,
            (int) $datos['id_especialidad'],
            CarbonImmutable::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay(),
            $hora,
            $datos['motivo'] ?? null,
            $request->user()->id,
        );

        return redirect()
            ->route('citas.show', $cita)
            ->with('status', "Cita {$cita->codigo} registrada correctamente.");
    }

    public function show(Cita $cita): View
    {
        $cita->load(['paciente.usuario', 'medico.usuario', 'especialidad', 'historial.registradoPor', 'canceladoPor']);

        return view('citas.show', compact('cita'));
    }

    public function cancelar(Request $request, Cita $cita): RedirectResponse
    {
        $datos = $request->validateWithBag('cancelacion', [
            'motivo_cancelacion' => ['required', 'string', 'max:1000'],
        ], [
            'motivo_cancelacion.required' => 'Indica el motivo de la cancelación.',
            'motivo_cancelacion.max' => 'El motivo no debe superar los :max caracteres.',
        ]);

        $resultado = DB::transaction(function () use ($cita, $datos, $request) {
            $fila = Cita::whereKey($cita->id_cita)->lockForUpdate()->first();

            if (! $fila->esCancelable()) {
                return ['error' => 'La cita ya no se puede cancelar: solo se cancelan citas programadas o confirmadas que todavía no empezaron.'];
            }

            $ahora = now();
            $anterior = $fila->estado;
            $fila->update([
                'estado' => Cita::CANCELADA,
                'motivo_cancelacion' => $datos['motivo_cancelacion'],
                'fecha_cancelacion' => $ahora,
                'cancelado_por' => $request->user()->id,
            ]);

            HistorialEstadoCita::create([
                'id_cita' => $fila->id_cita,
                'estado_anterior' => $anterior,
                'estado_nuevo' => Cita::CANCELADA,
                'fecha_hora' => $ahora,
                'motivo' => $datos['motivo_cancelacion'],
                'registrado_por' => $request->user()->id,
            ]);

            Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::CITAS, (int) $fila->id_cita,
                "Cita {$fila->codigo}: {$anterior} → " . Cita::CANCELADA . ". Motivo: {$datos['motivo_cancelacion']}");

            return ['status' => "Cita {$fila->codigo} cancelada. La franja vuelve a estar disponible."];
        });

        return redirect()
            ->route('citas.show', $cita)
            ->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status']);
    }

    /**
     * Pacientes que pueden recibir una cita: cuenta activa y sin bloqueo.
     */
    private function pacientesHabilitados()
    {
        return Paciente::query()
            ->select('paciente.*')
            ->join('users', 'users.id', '=', 'paciente.id_paciente')
            ->where('users.estado', User::ESTADO_ACTIVO)
            ->where('paciente.bloqueado', false)
            ->with('usuario')
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->get();
    }

    /**
     * Código único de la cita (citas.codigo es UNIQUE y obligatorio): CIT-AAAAMMDD-XXXXX.
     */
    private function fechaValida(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) ? $valor : null;
    }
}
