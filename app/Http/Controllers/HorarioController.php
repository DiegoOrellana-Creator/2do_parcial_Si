<?php

namespace App\Http\Controllers;

use App\Http\Requests\HorarioRequest;
use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\User;
use App\Services\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HorarioController extends Controller implements HasMiddleware
{
    /**
     * Cada operación exige su propio permiso (ver AppServiceProvider).
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:horarios.ver', only: ['index']),
            new Middleware('can:horarios.crear', only: ['create', 'store']),
            new Middleware('can:horarios.editar', only: ['edit', 'update']),
            new Middleware('can:horarios.eliminar', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $idMedico = (int) $request->query('medico', 0);
        $dia = (int) $request->query('dia', 0);
        $dia = array_key_exists($dia, HorarioAtencion::DIAS) ? $dia : 0;

        $horarios = HorarioAtencion::query()
            ->select('horario_atencion.*')
            ->join('users', 'users.id', '=', 'horario_atencion.id_medico')
            ->with('medico.usuario')
            ->when($idMedico > 0, fn ($q) => $q->where('horario_atencion.id_medico', $idMedico))
            ->when($dia > 0, fn ($q) => $q->where('horario_atencion.dia_semana', $dia))
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->orderBy('horario_atencion.dia_semana')
            ->orderBy('horario_atencion.hora_inicio')
            ->paginate(15)
            ->withQueryString();

        $medicos = $this->medicos();

        return view('horarios.index', compact('horarios', 'medicos', 'idMedico', 'dia'));
    }

    public function create(Request $request): View
    {
        $horario = new HorarioAtencion(['id_medico' => (int) $request->query('medico') ?: null, 'duracion_cita' => 30]);

        return view('horarios.create', ['horario' => $horario, 'medicos' => $this->medicos(soloActivos: true)]);
    }

    public function store(HorarioRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos) {
            // Serializa con el registro de citas y otros cambios de horario del mismo médico.
            Medico::whereKey($datos['id_medico'])->lockForUpdate()->first();

            $horario = HorarioAtencion::create($this->normalizar($datos) + ['id_medico' => $datos['id_medico']]);

            Auditoria::registrar(Auditoria::CREAR, Auditoria::HORARIOS, (int) $horario->id_horario, 'Horario registrado: ' . $this->describir($horario) . '.');
        });

        return redirect()
            ->route('horarios.index', ['medico' => $datos['id_medico']])
            ->with('status', 'Horario de atención registrado correctamente.');
    }

    public function edit(HorarioAtencion $horario): View
    {
        $horario->load('medico.usuario');
        $pendientes = $this->citasPendientesEn($horario);

        return view('horarios.edit', compact('horario', 'pendientes'));
    }

    public function update(HorarioRequest $request, HorarioAtencion $horario): RedirectResponse
    {
        $datos = $this->normalizar($request->validated());

        DB::transaction(function () use ($horario, $datos) {
            Medico::whereKey($horario->id_medico)->lockForUpdate()->first();
            $horario->refresh();

            // Cambiar un horario con citas pendientes las dejaría fuera de la agenda del médico.
            if ($pendientes = $this->citasPendientesEn($horario)) {
                throw ValidationException::withMessages(['hora_inicio' => "No se puede modificar: hay {$pendientes} "
                    . ($pendientes === 1 ? 'cita pendiente' : 'citas pendientes') . ' en este horario. Cancélalas antes.']);
            }

            $antes = $this->describir($horario);
            $horario->update($datos);

            if ($horario->wasChanged()) {
                Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::HORARIOS, (int) $horario->id_horario, "Horario modificado: {$antes} → " . $this->describir($horario) . '.');
            }
        });

        return redirect()
            ->route('horarios.index', ['medico' => $horario->id_medico])
            ->with('status', 'Horario de atención actualizado correctamente.');
    }

    public function destroy(HorarioAtencion $horario): RedirectResponse
    {
        $resultado = DB::transaction(function () use ($horario) {
            Medico::whereKey($horario->id_medico)->lockForUpdate()->first();

            if ($pendientes = $this->citasPendientesEn($horario)) {
                return ['error' => "No se puede eliminar el horario: hay {$pendientes} "
                    . ($pendientes === 1 ? 'cita pendiente' : 'citas pendientes') . ' en él. Cancélalas antes.'];
            }

            $descripcion = $this->describir($horario);
            $horario->delete();

            Auditoria::registrar(Auditoria::ELIMINAR, Auditoria::HORARIOS, (int) $horario->id_horario, "Horario eliminado: {$descripcion}.");

            return ['status' => 'Horario de atención eliminado correctamente.'];
        });

        return redirect()
            ->route('horarios.index', ['medico' => $horario->id_medico])
            ->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status']);
    }

    /**
     * Citas vigentes (Programada o Confirmada) desde hoy que caen dentro de este bloque semanal.
     */
    private function citasPendientesEn(HorarioAtencion $horario): int
    {
        return Cita::where('id_medico', $horario->id_medico)
            ->whereIn('estado', Cita::ESTADOS_CANCELABLES)
            ->where('fecha_cita', '>=', now()->toDateString())
            ->where('hora_inicio', '>=', $horario->hora_inicio)
            ->where('hora_fin', '<=', $horario->hora_final)
            ->get(['fecha_cita'])
            ->filter(fn ($cita) => CarbonImmutable::parse($cita->fecha_cita)->dayOfWeekIso === (int) $horario->dia_semana)
            ->count();
    }

    /** Ejemplo: «Sábado 12:15–12:45, citas de 30 min, Carla Méndez Vargas». */
    private function describir(HorarioAtencion $horario): string
    {
        $medico = User::find($horario->id_medico);

        return $horario->nombreDia() . ' ' . HorarioAtencion::hhmm($horario->hora_inicio) . '–' . HorarioAtencion::hhmm($horario->hora_final)
            . ", citas de {$horario->duracion_cita} min" . ($medico ? ", {$medico->nombre} {$medico->apellido}" : '');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizar(array $datos): array
    {
        return [
            'dia_semana' => (int) $datos['dia_semana'],
            'hora_inicio' => $datos['hora_inicio'] . ':00',
            'hora_final' => $datos['hora_final'] . ':00',
            'duracion_cita' => (int) $datos['duracion_cita'],
        ];
    }

    private function medicos(bool $soloActivos = false)
    {
        return Medico::query()
            ->select('medico.*')
            ->join('users', 'users.id', '=', 'medico.id_medico')
            ->when($soloActivos, fn ($q) => $q->where('users.estado', User::ESTADO_ACTIVO))
            ->with('usuario')
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->get();
    }
}
