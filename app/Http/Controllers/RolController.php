<?php

namespace App\Http\Controllers;

use App\Http\Requests\RolRequest;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Roles y permisos: exclusivo del administrador (Gate «roles.gestionar»). Crea roles y define qué
 * permisos tienen. No se borran roles: quitarlos arrastraría sus asignaciones.
 */
class RolController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:roles.gestionar')];
    }

    public function index(): View
    {
        $idsEnUso = Permiso::query()->enUso()->pluck('id_permiso');

        $roles = Rol::query()
            ->withCount([
                'permisos as total_permisos' => fn ($q) => $q->whereIn('permiso.id_permiso', $idsEnUso),
            ])
            ->addSelect(['total_usuarios' => DB::table('usuario_rol')->selectRaw('count(*)')
                ->whereColumn('usuario_rol.id_rol', 'rol.id_rol')->where('usuario_rol.estado', 'Activo')])
            ->orderBy('nombre')
            ->get();

        return view('roles.index', ['roles' => $roles, 'totalPermisos' => $idsEnUso->count()]);
    }

    public function create(): View
    {
        return view('roles.create', ['rol' => new Rol, 'modulos' => $this->permisosPorModulo(), 'marcados' => []]);
    }

    public function store(RolRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($datos) {
            $rol = Rol::create(['nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null]);
            $cambio = $this->sincronizarPermisos($rol, $datos['permisos'] ?? []);

            Auditoria::registrar(Auditoria::CREAR, Auditoria::ROLES, (int) $rol->id_rol, trim("Rol «{$rol->nombre}» creado. {$cambio}"));
        });

        return redirect()->route('roles.index')->with('status', 'Rol creado correctamente.');
    }

    public function edit(Rol $rol): View
    {
        return view('roles.edit', [
            'rol' => $rol,
            'modulos' => $this->permisosPorModulo(),
            'marcados' => $rol->permisos()->pluck('permiso.id_permiso')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    public function update(RolRequest $request, Rol $rol): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($rol, $datos) {
            $rol->update([
                'nombre' => $rol->esBase() ? $rol->nombre : $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
            ]);
            $cambio = $rol->permisosEditables() ? $this->sincronizarPermisos($rol, $datos['permisos'] ?? []) : '';

            if ($rol->wasChanged() || $cambio !== '') {
                Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::ROLES, (int) $rol->id_rol,
                    trim("Rol «{$rol->nombre}» actualizado. " . Auditoria::camposCambiados($rol) . " {$cambio}"));
            }
        });

        return redirect()->route('roles.index')->with('status', 'Rol actualizado correctamente.');
    }

    /**
     * Deja el rol con esos permisos. Solo toca los que la aplicación comprueba: el resto de filas
     * de `rol_permiso` se conserva. Devuelve la descripción del cambio para la bitácora.
     *
     * @param  array<int|string>  $ids
     */
    private function sincronizarPermisos(Rol $rol, array $ids): string
    {
        $enUso = Permiso::query()->enUso()->pluck('codigo', 'id_permiso');
        $nuevos = collect($ids)->map(fn ($id) => (int) $id)->unique();
        $actuales = DB::table('rol_permiso')->where('id_rol', $rol->id_rol)->whereIn('id_permiso', $enUso->keys())
            ->pluck('id_permiso')->map(fn ($id) => (int) $id);

        $anadir = $nuevos->diff($actuales);
        $quitar = $actuales->diff($nuevos);

        if ($quitar->isNotEmpty()) {
            DB::table('rol_permiso')->where('id_rol', $rol->id_rol)->whereIn('id_permiso', $quitar)->delete();
        }
        foreach ($anadir as $id) {
            DB::table('rol_permiso')->insert(['id_rol' => $rol->id_rol, 'id_permiso' => $id]);
        }

        $codigos = fn ($lista) => $lista->map(fn ($id) => $enUso[$id])->sort()->join(', ');

        return trim(($anadir->isNotEmpty() ? 'Permisos añadidos: ' . $codigos($anadir) . '.' : '')
            . ($quitar->isNotEmpty() ? ' Permisos quitados: ' . $codigos($quitar) . '.' : ''));
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Permiso>>
     */
    private function permisosPorModulo()
    {
        return Permiso::query()->enUso()->orderBy('codigo')->get()->groupBy(fn (Permiso $p) => $p->modulo());
    }
}
