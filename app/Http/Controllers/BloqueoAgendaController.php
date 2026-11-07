<?php

namespace App\Http\Controllers;

use App\Http\Requests\BloqueoAgendaRequest;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bloqueos de agenda: periodos en los que un médico no atiende. Lo usa quien gestiona las citas
 * (Gate «agenda.bloquear»). Un bloqueo no se edita: se quita y se registra otro.
 */
class BloqueoAgendaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:agenda.bloquear')];
    }

    public function index(Request $request): View
    {
        $idMedico = (int) $request->query('medico', 0);
        $desde = $this->fechaValida($request->query('desde')) ?? now()->toDateString();

        $bloqueos = BloqueoAgenda::query()
            ->with('medico.usuario')
            ->where('fecha', '>=', $desde)
            ->when($idMedico > 0, fn ($q) => $q->where('id_medico', $idMedico))
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->paginate(15)
            ->withQueryString();

        return view('agenda.bloqueos.index', [
            'bloqueos' => $bloqueos,
            'medicos' => $this->medicos(),
            'idMedico' => $idMedico,
            'desde' => $desde,
        ]);
    }

    public function create(Request $request): View
    {
        return view('agenda.bloqueos.create', [
            'medicos' => $this->medicos(soloActivos: true),
            'idMedico' => (int) $request->query('medico', 0),
        ]);
    }

    public function store(BloqueoAgendaRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $inicio = $datos['hora_inicio'] . ':00';
        $final = $datos['hora_final'] . ':00';

        DB::transaction(function () use ($datos, $inicio, $final) {
            // Mismo bloqueo que al registrar una cita: no puede aparecer una mientras se comprueba.
            $medico = Medico::with('usuario')->whereKey($datos['id_medico'])->lockForUpdate()->firstOrFail();

            if (BloqueoAgenda::where('id_medico', $medico->id_medico)->where('fecha', $datos['fecha'])
                ->where('hora_inicio', '<', $final)->where('hora_final', '>', $inicio)->exists()) {
                throw ValidationException::withMessages(['hora_inicio' => 'Ese periodo se superpone con otro bloqueo del médico.']);
            }

            $pendientes = Cita::where('id_medico', $medico->id_medico)->where('fecha_cita', $datos['fecha'])
                ->whereIn('estado', Cita::ESTADOS_CANCELABLES)
                ->where('hora_inicio', '<', $final)->where('hora_fin', '>', $inicio)
                ->count();
            if ($pendientes) {
                throw ValidationException::withMessages(['hora_inicio' => "No se puede bloquear: hay {$pendientes} "
                    . ($pendientes === 1 ? 'cita pendiente' : 'citas pendientes') . ' en ese periodo. Cancélalas o reprográmalas antes.']);
            }

            $bloqueo = BloqueoAgenda::create([
                'id_medico' => $medico->id_medico,
                'fecha' => $datos['fecha'],
                'hora_inicio' => $inicio,
                'hora_final' => $final,
                'motivo' => $datos['motivo'],
            ]);

            Auditoria::registrar(Auditoria::CREAR, Auditoria::AGENDA, (int) $bloqueo->id_bloqueo, 'Bloqueo registrado: ' . $this->describir($bloqueo, $medico) . '.');
        });

        return redirect()
            ->route('agenda.bloqueos.index', ['medico' => $datos['id_medico']])
            ->with('status', 'Bloqueo de agenda registrado correctamente.');
    }

    public function destroy(BloqueoAgenda $bloqueo): RedirectResponse
    {
        $resultado = DB::transaction(function () use ($bloqueo) {
            $medico = Medico::with('usuario')->whereKey($bloqueo->id_medico)->lockForUpdate()->first();
            $fila = BloqueoAgenda::whereKey($bloqueo->id_bloqueo)->lockForUpdate()->first();

            if (! $fila) {
                return ['error' => 'El bloqueo ya no existe.'];
            }
            if ($fila->haFinalizado()) {
                return ['error' => 'Un bloqueo que ya terminó no se puede quitar: se conserva como historial.'];
            }

            $descripcion = $this->describir($fila, $medico);
            $fila->delete();

            Auditoria::registrar(Auditoria::ELIMINAR, Auditoria::AGENDA, (int) $bloqueo->id_bloqueo, "Bloqueo quitado: {$descripcion}.");

            return ['status' => 'Bloqueo de agenda quitado. Esas franjas vuelven a estar disponibles.'];
        });

        return redirect()
            ->route('agenda.bloqueos.index', ['medico' => $bloqueo->id_medico])
            ->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status']);
    }

    /** Ejemplo: «09/11/2026 10:00–12:00, Carlos Méndez. Motivo: Congreso». */
    private function describir(BloqueoAgenda $bloqueo, ?Medico $medico): string
    {
        $nombre = $medico?->usuario ? "{$medico->usuario->nombre} {$medico->usuario->apellido}" : "médico #{$bloqueo->id_medico}";

        return Carbon::parse($bloqueo->fecha)->format('d/m/Y') . ' ' . HorarioAtencion::hhmm($bloqueo->hora_inicio) . '–' . HorarioAtencion::hhmm($bloqueo->hora_final)
            . ", {$nombre}. Motivo: {$bloqueo->motivo}";
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

    private function fechaValida(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) ? $valor : null;
    }
}
