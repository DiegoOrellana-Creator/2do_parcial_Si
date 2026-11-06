<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicoRequest;
use App\Models\Especialidad;
use App\Models\Medico;
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

class MedicoController extends Controller implements HasMiddleware
{
    private const ROL_MEDICO = 'Médico';

    /**
     * Cada operación exige su propio permiso (ver AppServiceProvider).
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:medicos.ver', only: ['index', 'show']),
            new Middleware('can:medicos.crear', only: ['create', 'store']),
            new Middleware('can:medicos.editar', only: ['edit', 'update']),
        ];
    }

    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q', ''));
        $estado = in_array($request->query('estado'), User::ESTADOS, true) ? $request->query('estado') : '';
        $idEspecialidad = (int) $request->query('especialidad', 0);

        $medicos = Medico::query()
            ->select('medico.*')
            ->join('users', 'users.id', '=', 'medico.id_medico')
            ->with(['usuario', 'especialidades'])
            ->when($busqueda !== '', function ($query) use ($busqueda) {
                $termino = '%' . mb_strtolower($busqueda) . '%';

                $query->where(function ($q) use ($termino) {
                    $q->whereRaw("LOWER(users.nombre || ' ' || users.apellido) LIKE ?", [$termino])
                        ->orWhereRaw('LOWER(users.ci) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(users.email) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(medico.matricula) LIKE ?', [$termino])
                        ->orWhereRaw('LOWER(medico.codigo) LIKE ?', [$termino]);
                });
            })
            ->when($estado !== '', fn ($q) => $q->where('users.estado', $estado))
            ->when($idEspecialidad > 0, fn ($q) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('especialidad_medico')
                ->whereColumn('especialidad_medico.id_medico', 'medico.id_medico')
                ->where('especialidad_medico.id_especialidad', $idEspecialidad)))
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->paginate(10)
            ->withQueryString();

        $especialidades = Especialidad::orderBy('nombre')->get(['id_especialidad', 'nombre', 'estado']);

        return view('medicos.index', compact('medicos', 'busqueda', 'estado', 'idEspecialidad', 'especialidades'));
    }

    public function create(): View
    {
        $medico = new Medico;

        return view('medicos.create', [
            'medico' => $medico,
            'especialidades' => $this->especialidadesParaFormulario($medico),
        ]);
    }

    public function store(MedicoRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        // Cuenta, ficha, especialidades y rol se guardan juntos: si algo falla, no queda una cuenta incompleta.
        $medico = DB::transaction(function () use ($datos) {
            // El médico es un usuario del sistema e inicia sesión con la contraseña asignada aquí.
            $usuario = User::create([
                ...$this->datosUsuario($datos),
                'estado' => $datos['estado'],
                'password' => Hash::make($datos['password']),
            ]);

            $medico = Medico::create([
                'id_medico' => $usuario->id,
                'matricula' => $datos['matricula'] ?? null,
                'codigo' => $datos['codigo'] ?? null,
            ]);

            $medico->especialidades()->attach(array_map('intval', $datos['especialidades']));

            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => $this->idRolMedico()]);

            Auditoria::registrar(Auditoria::CREAR, Auditoria::MEDICOS, (int) $usuario->id,
                "Médico «{$usuario->nombre} {$usuario->apellido}» registrado con su cuenta de acceso «{$usuario->username}».");

            return $medico;
        });

        return redirect()
            ->route('medicos.show', $medico)
            ->with('status', 'Médico registrado correctamente.');
    }

    public function show(Medico $medico): View
    {
        $medico->load(['usuario', 'especialidades']);
        $dependencias = new DependenciasUsuario((int) $medico->id_medico);

        $resumen = [
            'citas' => $dependencias->citasComoMedico(),
            'citasPendientes' => $dependencias->citasPendientesComoMedico(),
            'horarios' => $dependencias->horariosComoMedico(),
            'bloqueos' => $dependencias->bloqueosAgendaComoMedico(),
        ];

        return view('medicos.show', compact('medico', 'resumen'));
    }

    public function edit(Medico $medico): View
    {
        $medico->load(['usuario', 'especialidades']);

        return view('medicos.edit', [
            'medico' => $medico,
            'especialidades' => $this->especialidadesParaFormulario($medico),
        ]);
    }

    public function update(MedicoRequest $request, Medico $medico): RedirectResponse
    {
        $datos = $request->validated();
        $id = (int) $medico->id_medico;

        DB::transaction(function () use ($datos, $id, $request) {
            // Bloquear las filas: las comprobaciones siguientes no pueden quedar desactualizadas
            // por una cita registrada al mismo tiempo para este médico.
            $usuario = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $ficha = Medico::whereKey($id)->lockForUpdate()->firstOrFail();
            $dependencias = new DependenciasUsuario($id);

            // Desactivar: la cuenta deja de poder iniciar sesión y el médico deja de estar disponible.
            if ($datos['estado'] !== User::ESTADO_ACTIVO && $usuario->estado === User::ESTADO_ACTIVO) {
                if ($id === (int) $request->user()->id) {
                    throw ValidationException::withMessages(['estado' => 'No puedes desactivar tu propia cuenta.']);
                }
                if ($dependencias->esCuentaPrincipal()) {
                    throw ValidationException::withMessages(['estado' => 'La cuenta administrativa principal no se puede desactivar.']);
                }
                if ($pendientes = $dependencias->citasPendientesComoMedico()) {
                    throw ValidationException::withMessages(['estado' => "No se puede desactivar: el médico tiene {$pendientes} "
                        . ($pendientes === 1 ? 'cita pendiente' : 'citas pendientes') . '. Cancélalas o reasígnalas antes.']);
                }
            }

            // Quitar especialidades: no se permite si quedan citas pendientes de esa especialidad.
            $nuevas = array_map('intval', $datos['especialidades']);
            $actuales = $ficha->especialidades()->pluck('especialidad.id_especialidad')->map(fn ($v) => (int) $v)->all();
            foreach (array_diff($actuales, $nuevas) as $quitada) {
                if ($pendientes = $dependencias->citasPendientesComoMedico($quitada)) {
                    $nombre = Especialidad::whereKey($quitada)->value('nombre');
                    throw ValidationException::withMessages(['especialidades' => "No se puede quitar «{$nombre}»: el médico tiene {$pendientes} "
                        . ($pendientes === 1 ? 'cita pendiente' : 'citas pendientes') . ' de esa especialidad.']);
                }
            }

            $estadoAnterior = $usuario->estado;
            $usuario->update([...$this->datosUsuario($datos), 'estado' => $datos['estado']]);
            $ficha->update(['matricula' => $datos['matricula'] ?? null, 'codigo' => $datos['codigo'] ?? null]);
            $cambiosEspecialidades = $ficha->especialidades()->sync($nuevas);

            $nombre = "{$usuario->nombre} {$usuario->apellido}";
            $especialidadesCambiadas = $cambiosEspecialidades['attached'] || $cambiosEspecialidades['detached'];
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::MEDICOS, $id, trim("Datos del médico «{$nombre}» guardados. "
                . Auditoria::camposCambiados($usuario, $ficha) . ($especialidadesCambiadas ? ' Especialidades actualizadas.' : '')));

            if ($estadoAnterior !== $usuario->estado) {
                Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::MEDICOS, $id, "Cuenta del médico «{$nombre}»: {$estadoAnterior} → {$usuario->estado}.");
            }
        });

        return redirect()
            ->route('medicos.show', $medico)
            ->with('status', 'Datos del médico actualizados correctamente.');
    }

    /**
     * Especialidades que se ofrecen en el formulario: todas las activas y, además,
     * las inactivas que el médico ya tiene asignadas (para poder conservarlas).
     */
    private function especialidadesParaFormulario(Medico $medico)
    {
        $asignadas = $medico->exists ? $medico->especialidades->pluck('id_especialidad')->all() : [];

        return Especialidad::query()
            ->where('estado', Especialidad::ACTIVA)
            ->when($asignadas, fn ($q) => $q->orWhereIn('id_especialidad', $asignadas))
            ->orderBy('nombre')
            ->get(['id_especialidad', 'nombre', 'estado']);
    }

    /**
     * Rol «Médico» de la tabla `rol`. Si aún no existe, se crea (sin permisos: solo identifica el
     * perfil; User::perfil() no lo cuenta como personal administrativo).
     */
    private function idRolMedico(): int
    {
        $id = DB::table('rol')->where('nombre', self::ROL_MEDICO)->orderBy('id_rol')->value('id_rol');

        return (int) ($id ?? DB::table('rol')->insertGetId(
            ['nombre' => self::ROL_MEDICO, 'descripcion' => 'Personal médico de la clínica.'],
            'id_rol'
        ));
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
}
