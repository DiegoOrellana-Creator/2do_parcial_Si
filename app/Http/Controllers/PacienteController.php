<?php

namespace App\Http\Controllers;

use App\Http\Requests\PacienteRequest;
use App\Models\Cita;
use App\Models\Paciente;
use App\Models\User;
use App\Services\AsistenciaCita;
use App\Services\Auditoria;
use App\Services\DependenciasUsuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PacienteController extends Controller implements HasMiddleware
{
    /**
     * Cada operación exige su propio permiso (ver AppServiceProvider).
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:pacientes.ver', only: ['index', 'show', 'citas']),
            new Middleware('can:pacientes.crear', only: ['create', 'store']),
            new Middleware('can:pacientes.editar', only: ['edit', 'update']),
            new Middleware('can:pacientes.desbloquear', only: ['desbloquear']),
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
        $citas = Cita::sinReservas(DB::table('citas'))
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

        $totalCitas = Cita::where('id_paciente', $paciente->id_paciente)->count();

        $bloqueos = DB::table('bloqueo_paciente')
            ->where('id_paciente', $paciente->id_paciente)
            ->orderByDesc('fecha_bloqueo')
            ->get();

        return view('pacientes.show', compact('paciente', 'citas', 'totalCitas', 'bloqueos'));
    }

    /**
     * Historial completo de citas del paciente, de la más reciente a la más antigua.
     */
    public function citas(Request $request, Paciente $paciente): View
    {
        $paciente->load('usuario');

        $filtros = [
            'estado' => in_array($request->query('estado'), Cita::ESTADOS, true) ? $request->query('estado') : '',
            'desde' => $this->fechaValida($request->query('desde')),
            'hasta' => $this->fechaValida($request->query('hasta')),
        ];

        $citas = Cita::where('id_paciente', $paciente->id_paciente)
            ->with(['medico.usuario', 'especialidad'])
            ->when($filtros['estado'] !== '', fn ($q) => $q->where('estado', $filtros['estado']))
            ->when($filtros['desde'], fn ($q) => $q->where('fecha_cita', '>=', $filtros['desde']))
            ->when($filtros['hasta'], fn ($q) => $q->where('fecha_cita', '<=', $filtros['hasta']))
            ->orderByDesc('fecha_cita')
            ->orderByDesc('hora_inicio')
            ->paginate(15)
            ->withQueryString();

        return view('pacientes.citas', compact('paciente', 'citas', 'filtros'));
    }

    private function fechaValida(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor) ? $valor : null;
    }

    public function edit(Paciente $paciente): View
    {
        $paciente->load(['usuario', 'informacionMedica', 'contactosEmergencia']);

        return view('pacientes.edit', compact('paciente'));
    }

    public function update(PacienteRequest $request, Paciente $paciente): RedirectResponse
    {
        $datos = $request->validated();

        DB::transaction(function () use ($paciente, $datos, $request) {
            // Mismo bloqueo que al registrar una cita: no puede aparecer una mientras se desactiva.
            Paciente::whereKey($paciente->id_paciente)->lockForUpdate()->first();

            $estadoAnterior = $paciente->usuario->estado;
            $estadoNuevo = $datos['estado'] ?? $estadoAnterior;

            // Desactivar: la cuenta deja de poder iniciar sesión y se conservan todos sus datos.
            if ($estadoNuevo !== User::ESTADO_ACTIVO && $estadoAnterior === User::ESTADO_ACTIVO) {
                $this->comprobarDesactivacion((int) $paciente->id_paciente, (int) $request->user()->id);
            }

            $paciente->usuario->update([...$this->datosUsuario($datos), 'estado' => $estadoNuevo]);
            $paciente->update(['nro_ficha' => $datos['nro_ficha'] ?? null]);

            $this->guardarInformacionMedica($paciente, $datos);
            $this->guardarContactoPrincipal($paciente, $datos);

            $usuario = $paciente->usuario;
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::PACIENTES, (int) $paciente->id_paciente,
                trim("Datos del paciente «{$usuario->nombre} {$usuario->apellido}» guardados. " . Auditoria::camposCambiados($usuario, $paciente)));

            if ($estadoAnterior !== $estadoNuevo) {
                Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::PACIENTES, (int) $paciente->id_paciente,
                    "Cuenta del paciente «{$usuario->nombre} {$usuario->apellido}»: {$estadoAnterior} → {$estadoNuevo}.");
            }
        });

        return redirect()
            ->route('pacientes.show', $paciente)
            ->with('status', 'Datos del paciente actualizados correctamente.');
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

    public function desbloquear(Request $request, Paciente $paciente, AsistenciaCita $asistencia): RedirectResponse
    {
        $resultado = $asistencia->desbloquear($paciente, $request->user()->id);

        return redirect()
            ->route('pacientes.show', $paciente)
            ->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status']);
    }

    /**
     * @throws ValidationException si la cuenta no se puede desactivar.
     */
    private function comprobarDesactivacion(int $id, int $idActual): void
    {
        $dependencias = new DependenciasUsuario($id);

        if ($id === $idActual) {
            throw ValidationException::withMessages(['estado' => 'No puedes desactivar tu propia cuenta.']);
        }
        if ($dependencias->esCuentaPrincipal()) {
            throw ValidationException::withMessages(['estado' => 'La cuenta administrativa principal no se puede desactivar.']);
        }
        if ($pendientes = $dependencias->citasPendientesComoPaciente()) {
            throw ValidationException::withMessages(['estado' => "No se puede desactivar: el paciente tiene {$pendientes} "
                . ($pendientes === 1 ? 'cita pendiente' : 'citas pendientes') . '. Cancélalas antes.']);
        }
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
