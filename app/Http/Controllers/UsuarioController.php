<?php

namespace App\Http\Controllers;

use App\Http\Requests\UsuarioRequest;
use App\Models\Rol;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\DependenciasUsuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Usuarios: exclusivo del administrador (Gate «usuarios.gestionar»). Lista todas las cuentas y
 * gestiona las del personal; pacientes y médicos se gestionan en sus módulos. No se borra ninguna cuenta.
 */
class UsuarioController extends Controller implements HasMiddleware
{
    /** Tipo de cuenta => [rol en `rol`, tabla de subtipo, columna de esa tabla]. */
    public const TIPOS = [
        'Recepcionista' => ['recepcionista', 'id_recepcionista'],
        'Administrador' => ['administrador', 'id_administrador'],
    ];

    /** Filtro de perfil => [tabla, columna]. */
    private const FILTROS = [
        'Administrador' => ['administrador', 'id_administrador'],
        'Recepcionista' => ['recepcionista', 'id_recepcionista'],
        'Médico' => ['medico', 'id_medico'],
        'Paciente' => ['paciente', 'id_paciente'],
    ];

    public static function middleware(): array
    {
        return [new Middleware('can:usuarios.gestionar')];
    }

    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q', ''));
        $estado = in_array($request->query('estado'), User::ESTADOS, true) ? $request->query('estado') : '';
        $perfil = array_key_exists((string) $request->query('perfil'), self::FILTROS) ? (string) $request->query('perfil') : '';

        $usuarios = User::query()
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $termino = '%' . mb_strtolower($busqueda) . '%';

                $query->where(function ($q) use ($termino) {
                    $q->whereRaw("LOWER(nombre || ' ' || apellido) LIKE ?", [$termino])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(username) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(ci) LIKE ?', [$termino]);
                });
            })
            ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
            ->when($perfil !== '', function ($q) use ($perfil) {
                // Por su ficha o por tener ese rol activo, igual que User::perfil().
                [$tabla, $columna] = self::FILTROS[$perfil];
                $q->where(fn ($sub) => $sub->whereIn('id', DB::table($tabla)->select($columna))
                    ->orWhereIn('id', DB::table('usuario_rol')->join('rol', 'rol.id_rol', '=', 'usuario_rol.id_rol')
                        ->where('rol.nombre', $perfil)->where('usuario_rol.estado', User::ESTADO_ACTIVO)->select('usuario_rol.id_usuario')));
            })
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('usuarios.index', [
            'usuarios' => $usuarios,
            'busqueda' => $busqueda,
            'estado' => $estado,
            'perfil' => $perfil,
            'perfiles' => array_keys(self::FILTROS),
        ]);
    }

    public function create(): View
    {
        return view('usuarios.create', ['usuario' => new User, 'tipos' => array_keys(self::TIPOS)]);
    }

    public function store(UsuarioRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        [$tabla, $columna] = self::TIPOS[$datos['tipo']];

        DB::transaction(function () use ($datos, $tabla, $columna) {
            $idRol = DB::table('rol')->where('nombre', $datos['tipo'])->orderBy('id_rol')->value('id_rol');
            if (! $idRol) {
                throw ValidationException::withMessages(['tipo' => "No existe el rol «{$datos['tipo']}»: no se creó la cuenta."]);
            }

            $usuario = User::create([
                ...$this->datosPersonales($datos),
                'username' => $datos['username'],
                'password' => Hash::make($datos['password']),
                'estado' => User::ESTADO_ACTIVO,
            ]);

            DB::table($tabla)->insert([$columna => $usuario->id]);
            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => $idRol]);

            Auditoria::registrar(Auditoria::CREAR, Auditoria::USUARIOS, (int) $usuario->id,
                "Cuenta de {$datos['tipo']} «{$usuario->username}» creada para {$usuario->nombre} {$usuario->apellido}.");
        });

        return redirect()->route('usuarios.index')->with('status', 'Cuenta creada correctamente.');
    }

    public function edit(User $usuario): View|RedirectResponse
    {
        return $this->redirigirASuModulo($usuario) ?? view('usuarios.edit', [
            'usuario' => $usuario,
            'puedeCambiarEstado' => $this->puedeCambiarEstado($usuario),
            'rolesAsignables' => Rol::query()->personalizados()->orderBy('nombre')->get(),
            'rolesActivos' => DB::table('usuario_rol')->where('id_usuario', $usuario->id)->where('estado', User::ESTADO_ACTIVO)
                ->pluck('id_rol')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    public function update(UsuarioRequest $request, User $usuario): RedirectResponse
    {
        if ($redireccion = $this->redirigirASuModulo($usuario)) {
            return $redireccion;
        }

        $datos = $request->validated();

        DB::transaction(function () use ($usuario, $datos, $request) {
            $fila = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            $estadoAnterior = $fila->estado;
            $estadoNuevo = $datos['estado'] ?? $estadoAnterior;

            if ($estadoNuevo !== $estadoAnterior) {
                if ((int) $fila->id === (int) $request->user()->id) {
                    throw ValidationException::withMessages(['estado' => 'No puedes cambiar el estado de tu propia cuenta.']);
                }
                if ((new DependenciasUsuario((int) $fila->id))->esCuentaPrincipal()) {
                    throw ValidationException::withMessages(['estado' => 'La cuenta administrativa principal no se puede desactivar.']);
                }
            }

            $fila->update([...$this->datosPersonales($datos), 'estado' => $estadoNuevo]);

            if ($fila->wasChanged()) {
                Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::USUARIOS, (int) $fila->id,
                    trim("Cuenta «{$fila->username}» actualizada. " . Auditoria::camposCambiados($fila)));
            }
            if ($estadoAnterior !== $estadoNuevo) {
                Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::USUARIOS, (int) $fila->id,
                    "Cuenta «{$fila->username}»: {$estadoAnterior} → {$estadoNuevo}.");
            }

            if ($datos['roles_enviados'] ?? false) {
                $this->sincronizarRoles($fila, $datos['roles'] ?? []);
            }
        });

        return redirect()->route('usuarios.index')->with('status', 'Cuenta actualizada correctamente.');
    }

    /**
     * Asigna roles personalizados. Quitar uno lo marca «Inactivo» en `usuario_rol` (se conserva la
     * asignación); los roles del sistema no se tocan aquí.
     *
     * @param  array<int|string>  $ids
     */
    private function sincronizarRoles(User $usuario, array $ids): void
    {
        $personalizados = Rol::query()->personalizados()->pluck('nombre', 'id_rol');
        $nuevos = collect($ids)->map(fn ($id) => (int) $id)->unique();
        $filas = DB::table('usuario_rol')->where('id_usuario', $usuario->id)->whereIn('id_rol', $personalizados->keys())->pluck('estado', 'id_rol');
        $activos = $filas->filter(fn ($estado) => $estado === User::ESTADO_ACTIVO)->keys()->map(fn ($id) => (int) $id);

        $anadir = $nuevos->diff($activos);
        $quitar = $activos->diff($nuevos);

        foreach ($anadir as $id) {
            DB::table('usuario_rol')->updateOrInsert(['id_usuario' => $usuario->id, 'id_rol' => $id], ['estado' => User::ESTADO_ACTIVO]);
        }
        if ($quitar->isNotEmpty()) {
            DB::table('usuario_rol')->where('id_usuario', $usuario->id)->whereIn('id_rol', $quitar)->update(['estado' => User::ESTADO_INACTIVO]);
        }

        $nombres = fn ($lista) => $lista->map(fn ($id) => $personalizados[$id])->sort()->join(', ');
        $detalle = trim(($anadir->isNotEmpty() ? 'Roles asignados: ' . $nombres($anadir) . '.' : '') . ($quitar->isNotEmpty() ? ' Roles quitados: ' . $nombres($quitar) . '.' : ''));

        if ($detalle !== '') {
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::USUARIOS, (int) $usuario->id, "Cuenta «{$usuario->username}». {$detalle}");
        }
    }

    /**
     * Pacientes y médicos tienen reglas propias: se editan en su módulo.
     */
    private function redirigirASuModulo(User $usuario): ?RedirectResponse
    {
        return match ($usuario->perfil()) {
            User::PERFIL_PACIENTE => redirect()->route('pacientes.edit', $usuario->id),
            User::PERFIL_MEDICO => redirect()->route('medicos.edit', $usuario->id),
            default => null,
        };
    }

    private function puedeCambiarEstado(User $usuario): bool
    {
        return (int) $usuario->id !== (int) auth()->id() && ! (new DependenciasUsuario((int) $usuario->id))->esCuentaPrincipal();
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosPersonales(array $datos): array
    {
        return [
            'nombre' => $datos['nombre'],
            'apellido' => $datos['apellido'],
            'ci' => $datos['ci'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'direccion' => $datos['direccion'] ?? null,
            'email' => $datos['email'],
        ];
    }
}
