<?php

namespace App\Http\Controllers;

use App\Http\Requests\EspecialidadRequest;
use App\Models\Especialidad;
use App\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EspecialidadController extends Controller implements HasMiddleware
{
    /**
     * Cada operación exige su propio permiso (ver AppServiceProvider).
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:especialidades.ver', only: ['index', 'show']),
            new Middleware('can:especialidades.crear', only: ['create', 'store']),
            new Middleware('can:especialidades.editar', only: ['edit', 'update']),
            new Middleware('can:especialidades.eliminar', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q', ''));
        $estado = in_array($request->query('estado'), Especialidad::ESTADOS, true) ? $request->query('estado') : '';

        $especialidades = Especialidad::query()
            ->select('especialidad.*')
            ->selectSub(
                DB::table('especialidad_medico')->selectRaw('count(*)')->whereColumn('especialidad_medico.id_especialidad', 'especialidad.id_especialidad'),
                'total_medicos'
            )
            ->when($busqueda !== '', fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', ['%' . mb_strtolower($busqueda) . '%']))
            ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString();

        return view('especialidades.index', compact('especialidades', 'busqueda', 'estado'));
    }

    public function create(): View
    {
        return view('especialidades.create', ['especialidad' => new Especialidad(['estado' => Especialidad::ACTIVA])]);
    }

    public function store(EspecialidadRequest $request): RedirectResponse
    {
        $especialidad = Especialidad::create($request->validated());

        Auditoria::registrar(Auditoria::CREAR, Auditoria::ESPECIALIDADES, (int) $especialidad->id_especialidad,
            "Especialidad «{$especialidad->nombre}» registrada ({$especialidad->estado}).");

        return redirect()
            ->route('especialidades.show', $especialidad)
            ->with('status', 'Especialidad registrada correctamente.');
    }

    public function show(Especialidad $especialidad): View
    {
        // Médicos que la ejercen: medico.id_medico coincide con users.id.
        $medicos = DB::table('especialidad_medico')
            ->join('medico', 'medico.id_medico', '=', 'especialidad_medico.id_medico')
            ->join('users', 'users.id', '=', 'medico.id_medico')
            ->where('especialidad_medico.id_especialidad', $especialidad->id_especialidad)
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->get(['users.nombre', 'users.apellido', 'users.estado', 'medico.matricula', 'medico.codigo']);

        $totalCitas = $especialidad->totalCitas();
        $impedimentos = $especialidad->impedimentosParaEliminar();

        return view('especialidades.show', compact('especialidad', 'medicos', 'totalCitas', 'impedimentos'));
    }

    public function edit(Especialidad $especialidad): View
    {
        return view('especialidades.edit', compact('especialidad'));
    }

    public function update(EspecialidadRequest $request, Especialidad $especialidad): RedirectResponse
    {
        $estadoAnterior = $especialidad->estado;
        $especialidad->update($request->validated());

        if ($especialidad->wasChanged()) {
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::ESPECIALIDADES, (int) $especialidad->id_especialidad,
                trim("Especialidad «{$especialidad->nombre}» actualizada. " . Auditoria::camposCambiados($especialidad)));
        }
        if ($especialidad->wasChanged('estado')) {
            Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::ESPECIALIDADES, (int) $especialidad->id_especialidad,
                "Especialidad «{$especialidad->nombre}»: {$estadoAnterior} → {$especialidad->estado}.");
        }

        return redirect()
            ->route('especialidades.show', $especialidad)
            ->with('status', 'Especialidad actualizada correctamente.');
    }

    public function destroy(Especialidad $especialidad): RedirectResponse
    {
        $id = (int) $especialidad->id_especialidad;
        $nombre = $especialidad->nombre;

        try {
            $resultado = DB::transaction(function () use ($especialidad) {
                // Bloquear la fila: un INSERT concurrente en especialidad_medico o citas que la
                // referencie queda en espera, así que la comprobación no puede quedar desactualizada.
                $fila = Especialidad::whereKey($especialidad->id_especialidad)->lockForUpdate()->first();

                if (! $fila) {
                    return ['error' => 'La especialidad ya no existe.'];
                }

                // Borrarla eliminaría en cascada sus asignaciones a médicos y sus citas.
                if ($impedimentos = $fila->impedimentosParaEliminar()) {
                    return ['error' => 'No se puede eliminar la especialidad porque ' . implode(' y ', $impedimentos)
                        . '. Puedes desactivarla para que deje de ofrecerse.'];
                }

                $fila->delete();

                return ['status' => 'Especialidad eliminada correctamente.'];
            });
        } catch (QueryException $e) {
            // 23503: la base de datos rechazó el borrado por una clave foránea (protección RESTRICT).
            if ($e->getCode() === '23503') {
                return back()->with('error', 'La base de datos impidió eliminar la especialidad porque tiene registros relacionados.');
            }

            throw $e;
        }

        if (isset($resultado['error'])) {
            return back()->with('error', $resultado['error']);
        }

        Auditoria::registrar(Auditoria::ELIMINAR, Auditoria::ESPECIALIDADES, $id, "Especialidad «{$nombre}» eliminada.");

        return redirect()
            ->route('especialidades.index')
            ->with('status', $resultado['status']);
    }
}
