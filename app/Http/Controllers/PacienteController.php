<?php

namespace App\Http\Controllers;

use App\Http\Requests\PacienteRequest;
use App\Models\Paciente;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\DependenciasUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PacienteController extends Controller implements HasMiddleware
{
    /**
     * Cada operación exige su propio permiso (ver AppServiceProvider).
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:pacientes.ver', only: ['index', 'show']),
            new Middleware('can:pacientes.crear', only: ['create', 'store']),
            new Middleware('can:pacientes.editar', only: ['edit', 'update']),
            new Middleware('can:pacientes.eliminar', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q', ''));

        $pacientes = Paciente::query()
            ->select('paciente.*')
            ->join('users', 'users.id', '=', 'paciente.id_paciente')
            ->with('usuario')
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $termino = '%' . mb_strtolower($busqueda) . '%';

                $query->where(function ($q) use ($termino) {
                    $q->whereRaw("LOWER(users.nombre || ' ' || users.apellido) LIKE ?", [$termino])
                        ->orWhereRaw('LOWER(users.ci) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(users.email) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(paciente.nro_ficha) LIKE ?', [$termino]);
                });
            })
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->paginate(10)
            ->withQueryString();

        return view('pacientes.index', compact('pacientes', 'busqueda'));
    }

    public function create(): View
    {
        return view('pacientes.create', ['paciente' => new Paciente]);
    }

    public function store(PacienteRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $paciente = DB::transaction(function () use ($datos) {
            // El paciente es un usuario del sistema. Se le asigna una contraseña
            // aleatoria; podrá definir la suya con «¿Olvidaste tu contraseña?».
            $usuario = User::create([
                ...$this->datosUsuario($datos),
                'password' => Str::password(32),
            ]);

            $paciente = Paciente::create([
                'id_paciente' => $usuario->id,
                'nro_ficha' => $datos['nro_ficha'] ?? null,
                'inasistencia' => 0,
                'bloqueado' => false,
            ]);

            $this->guardarInformacionMedica($paciente, $datos);
            $this->guardarContactoPrincipal($paciente, $datos);

            Auditoria::registrar(Auditoria::CREAR, Auditoria::PACIENTES, (int) $paciente->id_paciente,
                "Paciente «{$usuario->nombre} {$usuario->apellido}» registrado.");

            return $paciente;
        });

        return redirect()
            ->route('pacientes.show', $paciente)
            ->with('status', 'Paciente registrado correctamente.');
    }

    public function show(Paciente $paciente): View
    {
        $paciente->load(['usuario', 'informacionMedica', 'contactosEmergencia']);

        // Tablas sin modelo propio todavía: se consultan solo para mostrarlas.
        $citas = DB::table('citas')
            ->leftJoin('users as medico', 'medico.id', '=', 'citas.id_medico')
            ->leftJoin('especialidad', 'especialidad.id_especialidad', '=', 'citas.id_especialidad')
            ->where('citas.id_paciente', $paciente->id_paciente)
            ->orderByDesc('citas.fecha_cita')
            ->orderByDesc('citas.hora_inicio')
            ->limit(10)
            ->get([
                'citas.codigo', 'citas.fecha_cita', 'citas.hora_inicio', 'citas.estado',
                'medico.nombre as medico_nombre', 'medico.apellido as medico_apellido',
                'especialidad.nombre as especialidad',
            ]);

        $totalCitas = DB::table('citas')->where('id_paciente', $paciente->id_paciente)->count();

        $bloqueos = DB::table('bloqueo_paciente')
            ->where('id_paciente', $paciente->id_paciente)
            ->orderByDesc('fecha_bloqueo')
            ->get();

        // Datos para que la confirmación de borrado diga exactamente qué se eliminará.
        $dependencias = new DependenciasUsuario((int) $paciente->id_paciente);
        $eliminacion = [
            'impedimentos' => $dependencias->impedimentosParaEliminarPaciente(),
            'conservarCuenta' => $dependencias->motivosParaConservarCuenta(),
            'notificaciones' => $dependencias->notificaciones(),
            'preferencias' => $dependencias->tienePreferencias(),
        ];

        return view('pacientes.show', compact('paciente', 'citas', 'totalCitas', 'bloqueos', 'eliminacion'));
    }

    public function edit(Paciente $paciente): View
    {
        $paciente->load(['usuario', 'informacionMedica', 'contactosEmergencia']);

        return view('pacientes.edit', compact('paciente'));
    }

    public function update(PacienteRequest $request, Paciente $paciente): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($paciente, $datos) {
            $paciente->usuario->update($this->datosUsuario($datos));
            $paciente->update(['nro_ficha' => $datos['nro_ficha'] ?? null]);

            $this->guardarInformacionMedica($paciente, $datos);
            $this->guardarContactoPrincipal($paciente, $datos);

            $usuario = $paciente->usuario;
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::PACIENTES, (int) $paciente->id_paciente,
                trim("Datos del paciente «{$usuario->nombre} {$usuario->apellido}» guardados. " . Auditoria::camposCambiados($usuario, $paciente)));
        });

        return redirect()
            ->route('pacientes.show', $paciente)
            ->with('status', 'Datos del paciente actualizados correctamente.');
    }

    public function destroy(Request $request, Paciente $paciente): RedirectResponse
    {
        $id = (int) $paciente->id_paciente;

        if ($id === (int) $request->user()->id) {
            return back()->with('error', 'No puedes eliminar tu propio registro de paciente.');
        }

        $nombre = $paciente->usuario ? $paciente->usuario->nombre . ' ' . $paciente->usuario->apellido : "#{$id}";

        try {
            $resultado = DB::transaction(function () use ($id) {
                // Se bloquean primero las filas (usuario y luego paciente). Mientras dure la
                // transacción, un INSERT concurrente en citas para este paciente queda en espera,
                // así que la comprobación siguiente no puede quedar desactualizada.
                $usuario = User::whereKey($id)->lockForUpdate()->first();
                $ficha = Paciente::whereKey($id)->lockForUpdate()->first();

                if (! $usuario || ! $ficha) {
                    return ['error' => 'El paciente ya no existe.'];
                }

                $dependencias = new DependenciasUsuario($id);

                // Las citas (con sus pagos e historial) y los bloqueos se borrarían en cascada.
                if ($impedimentos = $dependencias->impedimentosParaEliminarPaciente()) {
                    return ['error' => 'No se puede eliminar al paciente porque ' . implode(' y ', $impedimentos) . '.'];
                }

                // Si la cuenta tiene otros roles o autoría en la auditoría, solo se quita la ficha.
                if ($motivos = $dependencias->motivosParaConservarCuenta()) {
                    $ficha->delete();

                    return ['status' => 'Se eliminó la ficha de paciente. La cuenta de acceso se conservó porque ' . implode(' y ', $motivos) . '.'];
                }

                // Borra la cuenta; en cascada se eliminan ficha, información médica, contactos,
                // notificaciones y preferencias (ver DependenciasUsuario).
                $usuario->delete();

                return ['status' => 'Paciente eliminado correctamente.'];
            });
        } catch (QueryException $e) {
            // 23503: la base de datos rechazó el borrado por una clave foránea (protección RESTRICT).
            if ($e->getCode() === '23503') {
                return back()->with('error', 'La base de datos impidió eliminar al paciente porque tiene registros relacionados.');
            }

            throw $e;
        }

        if (isset($resultado['error'])) {
            return back()->with('error', $resultado['error']);
        }

        Auditoria::registrar(Auditoria::ELIMINAR, Auditoria::PACIENTES, $id, "Paciente «{$nombre}». {$resultado['status']}");

        return redirect()
            ->route('pacientes.index')
            ->with('status', $resultado['status']);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datosUsuario(array $datos): array
    {
        return [
            'nombre' => $datos['nombre'],
            'apellido' => $datos['apellido'],
            'ci' => $datos['ci'] ?? null,
            'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
            'sexo' => $datos['sexo'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'direccion' => $datos['direccion'] ?? null,
            'email' => $datos['email'],
            'username' => $datos['username'],
        ];
    }

    /**
     * Crea o actualiza la información médica. No crea una fila vacía.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarInformacionMedica(Paciente $paciente, array $datos): void
    {
        $campos = collect(['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'antecedentes'])
            ->mapWithKeys(fn ($campo) => [$campo => $datos[$campo] ?? null]);

        if ($campos->filter()->isEmpty() && ! $paciente->informacionMedica()->exists()) {
            return;
        }

        $paciente->informacionMedica()->updateOrCreate(
            ['id_paciente' => $paciente->id_paciente],
            $campos->all(),
        );
    }

    /**
     * Gestiona el contacto de emergencia principal (el primero registrado).
     * Si se dejan vacíos todos sus campos, el contacto principal se quita.
     *
     * @param  array<string, mixed>  $datos
     */
    private function guardarContactoPrincipal(Paciente $paciente, array $datos): void
    {
        $contacto = $paciente->contactosEmergencia()->orderBy('id_contacto')->first();

        if (empty($datos['contacto_nombre'])) {
            $contacto?->delete();

            return;
        }

        $valores = [
            'nombre' => $datos['contacto_nombre'],
            'parentesco' => $datos['contacto_parentesco'] ?? null,
            'telefono' => $datos['contacto_telefono'] ?? null,
        ];

        $contacto
            ? $contacto->update($valores)
            : $paciente->contactosEmergencia()->create($valores);
    }
}
