<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\User;
use App\Services\Disponibilidad;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Consultar agenda médica: franjas y citas de un médico en un día.
 *  - Personal con el permiso «agenda.ver»: cualquier médico.
 *  - Médico: solo su propia agenda (otro médico → 403).
 *  - Pacientes y cuentas sin perfil: sin acceso.
 */
class AgendaController extends Controller
{
    public function index(Request $request, Disponibilidad $disponibilidad): View
    {
        $usuario = $request->user();
        $perfil = $usuario->perfil();
        $pedido = (int) $request->query('medico', 0);

        if ($perfil === User::PERFIL_PERSONAL) {
            abort_unless(Gate::allows('agenda.ver'), 403);
            $medicos = Medico::query()->select('medico.*')->join('users', 'users.id', '=', 'medico.id_medico')
                ->with('usuario')->orderBy('users.apellido')->orderBy('users.nombre')->get();
            $idMedico = $pedido ?: (int) ($medicos->first()?->id_medico ?? 0);
        } elseif ($perfil === User::PERFIL_MEDICO) {
            // La agenda de un médico es privada: solo puede ver la suya.
            abort_if($pedido && $pedido !== (int) $usuario->id, 403);
            $medicos = collect();
            $idMedico = (int) $usuario->id;
        } else {
            abort(403);
        }

        $fecha = $this->fecha($request->query('fecha'));
        $medico = $idMedico ? Medico::with(['usuario', 'especialidades'])->find($idMedico) : null;
        abort_if($idMedico && ! $medico, 404);

        $franjas = $medico ? $disponibilidad->franjas($idMedico, $fecha) : collect();

        // Todas las citas del día, también las canceladas o las que quedaron fuera del horario actual.
        $citas = $medico
            ? Cita::with(['paciente.usuario', 'especialidad'])->where('id_medico', $idMedico)
                ->where('fecha_cita', $fecha->toDateString())->orderBy('hora_inicio')->get()
            : collect();

        $horarios = $medico
            ? HorarioAtencion::where('id_medico', $idMedico)->where('dia_semana', $fecha->dayOfWeekIso)->orderBy('hora_inicio')->get()
            : collect();

        return view('agenda.index', compact('perfil', 'medicos', 'medico', 'fecha', 'franjas', 'citas', 'horarios'));
    }

    private function fecha(mixed $valor): CarbonImmutable
    {
        if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            $fecha = CarbonImmutable::createFromFormat('Y-m-d', $valor);
            if ($fecha && $fecha->format('Y-m-d') === $valor) {
                return $fecha->startOfDay();
            }
        }

        return CarbonImmutable::today();
    }
}
