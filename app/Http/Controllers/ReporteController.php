<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Pago;
use App\Services\Auditoria;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes: solo consulta y solo para el administrador (Gate «reportes.ver»). Resume las citas de
 * un periodo (por la fecha de la cita) y los pagos cobrados en ese periodo (por la fecha de pago).
 */
class ReporteController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:reportes.ver')];
    }

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);
        $invertido = $filtros['desde'] > $filtros['hasta'];

        $porEstado = $this->citas($filtros)->groupBy('citas.estado')->selectRaw('citas.estado, count(*) as total')
            ->pluck('total', 'estado')->map(fn ($n) => (int) $n);
        $resumen = collect(Cita::ESTADOS)->mapWithKeys(fn ($estado) => [$estado => $porEstado[$estado] ?? 0]);
        $atendidas = $resumen[Cita::ATENDIDA];
        $inasistencias = $resumen[Cita::NO_ASISTIO];

        $pagos = $this->pagos($filtros)->selectRaw('count(*) as cantidad, coalesce(sum(pago.monto), 0) as total')->first();

        return view('reportes.index', [
            'filtros' => $filtros,
            'invertido' => $invertido,
            'resumen' => $resumen,
            'total' => $resumen->sum(),
            'tasaAsistencia' => $atendidas + $inasistencias > 0 ? round($atendidas / ($atendidas + $inasistencias) * 100) : null,
            'pagos' => ['cantidad' => (int) $pagos->cantidad, 'total' => (float) $pagos->total],
            'porMetodo' => $this->pagos($filtros)
                ->groupBy('pago.metodo_de_pago')
                ->selectRaw("coalesce(pago.metodo_de_pago, 'Sin método') as metodo, count(*) as cantidad, coalesce(sum(pago.monto), 0) as total")
                ->orderByDesc('total')->orderBy('metodo')
                ->get(),
            'porEspecialidad' => $this->desglose($filtros, ['especialidad.id_especialidad', 'especialidad.nombre'], 'especialidad.nombre'),
            'porMedico' => $this->desglose($filtros, ['citas.id_medico', 'medicos.nombre', 'medicos.apellido'], "medicos.nombre || ' ' || medicos.apellido"),
            'inasistentes' => $this->citas($filtros)
                ->join('users as pacientes', 'pacientes.id', '=', 'citas.id_paciente')
                ->join('paciente', 'paciente.id_paciente', '=', 'citas.id_paciente')
                ->where('citas.estado', Cita::NO_ASISTIO)
                ->groupBy('citas.id_paciente', 'pacientes.nombre', 'pacientes.apellido', 'paciente.nro_ficha', 'paciente.inasistencia', 'paciente.bloqueado')
                ->orderByRaw('count(*) desc')->orderBy('pacientes.apellido')->orderBy('pacientes.nombre')
                ->limit(10)
                ->get(['citas.id_paciente', 'pacientes.nombre', 'pacientes.apellido', 'paciente.nro_ficha', 'paciente.inasistencia', 'paciente.bloqueado', DB::raw('count(*) as total')]),
            'bloqueados' => DB::table('paciente')->where('bloqueado', true)->count(),
            'especialidades' => Especialidad::orderBy('nombre')->get(['id_especialidad', 'nombre']),
            'medicos' => Medico::query()->join('users', 'users.id', '=', 'medico.id_medico')
                ->orderBy('users.apellido')->orderBy('users.nombre')->get(['medico.id_medico', 'users.nombre', 'users.apellido']),
        ]);
    }

    /**
     * Detalle de las citas del periodo en CSV (separado por «;» y con BOM para que Excel lo abra
     * con acentos). Cada exportación queda en la bitácora porque contiene datos de pacientes.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $filtros = $this->filtros($request);
        $consulta = $this->citas($filtros)
            ->join('users as pacientes', 'pacientes.id', '=', 'citas.id_paciente')
            ->join('paciente', 'paciente.id_paciente', '=', 'citas.id_paciente')
            ->orderBy('citas.fecha_cita')->orderBy('citas.hora_inicio')->orderBy('citas.id_cita')
            ->select([
                'citas.id_cita', 'citas.codigo', 'citas.fecha_cita', 'citas.hora_inicio', 'citas.hora_fin', 'citas.estado', 'citas.motivo_cancelacion',
                'pacientes.nombre as paciente_nombre', 'pacientes.apellido as paciente_apellido', 'pacientes.ci as paciente_ci', 'paciente.nro_ficha',
                'medicos.nombre as medico_nombre', 'medicos.apellido as medico_apellido', 'especialidad.nombre as especialidad',
            ]);

        $total = (clone $consulta)->count();
        Auditoria::registrar(Auditoria::EXPORTAR, Auditoria::REPORTES, null,
            "Exportación de {$total} " . ($total === 1 ? 'cita' : 'citas') . " del {$this->fecha($filtros['desde'])} al {$this->fecha($filtros['hasta'])}"
            . ($filtros['especialidad'] ? ", especialidad #{$filtros['especialidad']}" : '')
            . ($filtros['medico'] ? ", médico #{$filtros['medico']}" : '') . '.');

        $archivo = "citas_{$filtros['desde']}_a_{$filtros['hasta']}.csv";

        return response()->streamDownload(function () use ($consulta) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Código', 'Fecha', 'Hora inicio', 'Hora fin', 'Paciente', 'CI', 'N.º de ficha', 'Médico', 'Especialidad', 'Estado', 'Motivo de cancelación'], ';');

            $consulta->chunkById(500, function ($citas) use ($salida) {
                foreach ($citas as $c) {
                    fputcsv($salida, array_map([$this, 'celda'], [
                        $c->codigo, $this->fecha($c->fecha_cita), substr($c->hora_inicio, 0, 5), substr($c->hora_fin, 0, 5),
                        "{$c->paciente_nombre} {$c->paciente_apellido}", $c->paciente_ci, $c->nro_ficha,
                        "{$c->medico_nombre} {$c->medico_apellido}", $c->especialidad, $c->estado, $c->motivo_cancelacion,
                    ]), ';');
                }
            }, 'citas.id_cita', 'id_cita');

            fclose($salida);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Evita que Excel interprete como fórmula un texto escrito por un usuario.
     */
    private function celda(mixed $valor): string
    {
        $texto = (string) $valor;

        return $texto !== '' && in_array($texto[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $texto : $texto;
    }

    /**
     * @return array{desde: string, hasta: string, especialidad: int, medico: int}
     */
    private function filtros(Request $request): array
    {
        $hoy = Carbon::today();

        return [
            'desde' => $this->fechaValida($request->query('desde')) ?? $hoy->copy()->startOfMonth()->toDateString(),
            'hasta' => $this->fechaValida($request->query('hasta')) ?? $hoy->copy()->endOfMonth()->toDateString(),
            'especialidad' => max(0, (int) $request->query('especialidad', 0)),
            'medico' => max(0, (int) $request->query('medico', 0)),
        ];
    }

    /**
     * Citas del periodo con su médico y especialidad.
     *
     * @param  array{desde: string, hasta: string, especialidad: int, medico: int}  $filtros
     */
    private function citas(array $filtros): Builder
    {
        return Cita::sinReservas(DB::table('citas'))
            ->join('especialidad', 'especialidad.id_especialidad', '=', 'citas.id_especialidad')
            ->join('users as medicos', 'medicos.id', '=', 'citas.id_medico')
            ->whereBetween('citas.fecha_cita', [$filtros['desde'], $filtros['hasta']])
            ->when($filtros['especialidad'], fn ($q) => $q->where('citas.id_especialidad', $filtros['especialidad']))
            ->when($filtros['medico'], fn ($q) => $q->where('citas.id_medico', $filtros['medico']));
    }

    /**
     * Pagos cobrados en el periodo, de citas que cumplen los filtros de especialidad y médico.
     *
     * @param  array{desde: string, hasta: string, especialidad: int, medico: int}  $filtros
     */
    private function pagos(array $filtros): Builder
    {
        return DB::table('pago')
            ->join('citas', 'citas.id_cita', '=', 'pago.id_cita')
            ->where('pago.estado_pago', Pago::PAGADO)
            ->whereBetween('pago.fecha_pago', [$filtros['desde'] . ' 00:00:00', $filtros['hasta'] . ' 23:59:59'])
            ->when($filtros['especialidad'], fn ($q) => $q->where('citas.id_especialidad', $filtros['especialidad']))
            ->when($filtros['medico'], fn ($q) => $q->where('citas.id_medico', $filtros['medico']));
    }

    /**
     * Totales por grupo: citas, atendidas, canceladas e inasistencias.
     *
     * @param  array{desde: string, hasta: string, especialidad: int, medico: int}  $filtros
     * @param  list<string>  $agrupar  columnas del GROUP BY
     * @param  string  $nombre  expresión SQL fija (no viene del usuario) con el nombre del grupo
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function desglose(array $filtros, array $agrupar, string $nombre)
    {
        $suma = fn (string $estado, string $alias) => DB::raw('sum(case when citas.estado = ' . DB::getPdo()->quote($estado) . " then 1 else 0 end) as {$alias}");

        return $this->citas($filtros)
            ->groupBy($agrupar)
            ->orderByRaw('count(*) desc')
            ->orderByRaw($nombre)
            ->get([
                DB::raw("{$nombre} as nombre"),
                DB::raw('count(*) as total'),
                $suma(Cita::ATENDIDA, 'atendidas'),
                $suma(Cita::CANCELADA, 'canceladas'),
                $suma(Cita::NO_ASISTIO, 'inasistencias'),
            ]);
    }

    private function fecha(string $fecha): string
    {
        return Carbon::parse($fecha)->format('d/m/Y');
    }

    private function fechaValida(mixed $valor): ?string
    {
        if (! is_string($valor) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $valor : null;
    }
}
