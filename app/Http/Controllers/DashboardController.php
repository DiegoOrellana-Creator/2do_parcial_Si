<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\HorarioAtencion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Inicio: el personal ve el panel de la clínica con datos reales; médicos, pacientes y
 * cuentas sin perfil ven su propia página de inicio (resources/views/inicio.blade.php).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        if ($request->user()->perfil() !== User::PERFIL_PERSONAL) {
            return view('inicio');
        }

        $hoy = CarbonImmutable::today();
        $ahora = CarbonImmutable::now();

        $estadisticas = [
            'citasHoy' => Cita::where('fecha_cita', $hoy->toDateString())->where('estado', '<>', Cita::CANCELADA)->count(),
            'pendientes' => Cita::whereIn('estado', Cita::ESTADOS_CANCELABLES)->where('fecha_cita', '>=', $hoy->toDateString())->count(),
            'pacientes' => DB::table('paciente')->count(),
            'medicosActivos' => DB::table('medico')->join('users', 'users.id', '=', 'medico.id_medico')
                ->where('users.estado', User::ESTADO_ACTIVO)->count(),
        ];

        $proximas = Cita::with(['paciente.usuario', 'medico.usuario', 'especialidad'])
            ->whereIn('estado', Cita::ESTADOS_CANCELABLES)
            ->where(fn ($q) => $q->where('fecha_cita', '>', $hoy->toDateString())
                ->orWhere(fn ($q) => $q->where('fecha_cita', $hoy->toDateString())->where('hora_inicio', '>=', $ahora->format('H:i:s'))))
            ->orderBy('fecha_cita')
            ->orderBy('hora_inicio')
            ->limit(6)
            ->get();

        // Médicos activos con horario de atención hoy (dia_semana ISO: 1 = lunes … 7 = domingo).
        $medicosHoy = HorarioAtencion::query()
            ->select('horario_atencion.*')
            ->join('users', 'users.id', '=', 'horario_atencion.id_medico')
            ->where('horario_atencion.dia_semana', $hoy->dayOfWeekIso)
            ->where('users.estado', User::ESTADO_ACTIVO)
            ->with('medico.usuario', 'medico.especialidades')
            ->orderBy('horario_atencion.hora_inicio')
            ->get()
            ->groupBy('id_medico')
            ->map(fn ($bloques) => [
                'medico' => $bloques->first()->medico,
                'bloques' => $bloques->map(fn ($b) => HorarioAtencion::hhmm($b->hora_inicio) . '–' . HorarioAtencion::hhmm($b->hora_final))->join(' · '),
            ])
            ->values();

        $porEspecialidad = DB::table('citas')
            ->join('especialidad', 'especialidad.id_especialidad', '=', 'citas.id_especialidad')
            ->whereBetween('citas.fecha_cita', [$hoy->startOfMonth()->toDateString(), $hoy->endOfMonth()->toDateString()])
            ->where('citas.estado', '<>', Cita::CANCELADA)
            ->groupBy('especialidad.nombre')
            ->orderByRaw('count(*) desc')
            ->orderBy('especialidad.nombre')
            ->limit(6)
            ->get(['especialidad.nombre', DB::raw('count(*) as total')]);

        return view('dashboard', compact('estadisticas', 'proximas', 'medicosHoy', 'porEspecialidad', 'hoy'));
    }
}
