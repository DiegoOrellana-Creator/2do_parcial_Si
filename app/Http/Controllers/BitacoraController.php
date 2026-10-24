<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Bitácora de auditoría: solo consulta y solo para el administrador (Gate «bitacora.ver»).
 * No hay rutas para crear, modificar ni eliminar registros: los escribe App\Services\Auditoria.
 */
class BitacoraController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:bitacora.ver')];
    }

    public function index(Request $request): View
    {
        $filtros = [
            'q' => trim((string) $request->query('q', '')),
            'desde' => $this->fechaValida($request->query('desde')),
            'hasta' => $this->fechaValida($request->query('hasta')),
            'usuario' => (int) $request->query('usuario', 0),
            'modulo' => (string) $request->query('modulo', ''),
            'accion' => (string) $request->query('accion', ''),
        ];

        $registros = Bitacora::query()
            ->select('bitacora.*')
            ->leftJoin('users', 'users.id', '=', 'bitacora.id_usuario')
            ->with('usuario')
            ->when($filtros['q'] !== '', function ($query) use ($filtros) {
                $termino = '%' . mb_strtolower($filtros['q']) . '%';

                $query->where(function ($q) use ($termino, $filtros) {
                    $q->whereRaw('LOWER(bitacora.detalle) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(bitacora.accion) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(bitacora.modulo) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(bitacora.ip) LIKE ?', [$termino])
                        ->orWhereRaw("LOWER(users.nombre || ' ' || users.apellido) LIKE ?", [$termino])
                        ->orWhereRaw('LOWER(users.username) LIKE ?', [$termino]);

                    if (ctype_digit($filtros['q'])) {
                        $q->orWhere('bitacora.id_registro', (int) $filtros['q']);
                    }
                });
            })
            ->when($filtros['desde'], fn ($q) => $q->where('bitacora.fecha_hora', '>=', $filtros['desde'] . ' 00:00:00'))
            ->when($filtros['hasta'], fn ($q) => $q->where('bitacora.fecha_hora', '<=', $filtros['hasta'] . ' 23:59:59'))
            ->when($filtros['usuario'] > 0, fn ($q) => $q->where('bitacora.id_usuario', $filtros['usuario']))
            ->when($filtros['modulo'] !== '', fn ($q) => $q->where('bitacora.modulo', $filtros['modulo']))
            ->when($filtros['accion'] !== '', fn ($q) => $q->where('bitacora.accion', $filtros['accion']))
            // Lo más reciente primero.
            ->orderByDesc('bitacora.fecha_hora')
            ->orderByDesc('bitacora.id_bitacora')
            ->paginate(20)
            ->withQueryString();

        return view('bitacora.index', [
            'registros' => $registros,
            'filtros' => $filtros,
            'usuarios' => User::whereIn('id', Bitacora::query()->whereNotNull('id_usuario')->select('id_usuario'))
                ->orderBy('apellido')->orderBy('nombre')->get(['id', 'nombre', 'apellido', 'username']),
            'modulos' => Bitacora::query()->whereNotNull('modulo')->distinct()->orderBy('modulo')->pluck('modulo'),
            'acciones' => Bitacora::query()->distinct()->orderBy('accion')->pluck('accion'),
            'rangoInvertido' => $filtros['desde'] && $filtros['hasta'] && $filtros['desde'] > $filtros['hasta'],
        ]);
    }

    private function fechaValida(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) ? $valor : null;
    }
}
