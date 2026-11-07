<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReprogramacionCitaRequest;
use App\Http\Requests\SolicitudCitaRequest;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Paciente;
use App\Services\Disponibilidad;
use App\Services\RegistroCita;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Funciones del perfil Paciente: disponibilidad médica, solicitar cita, mis citas, y cancelar o
 * reprogramar las propias con las mismas reglas que el personal.
 * Las rutas exigen el perfil «paciente» (middleware perfil); el paciente es siempre la cuenta
 * autenticada y nunca se toma un identificador de paciente de la petición.
 */
class MisCitasController extends Controller
{
    public function index(Request $request): View
    {
        $paciente = $this->paciente($request);
        $hoy = now()->toDateString();

        $base = fn () => Cita::where('id_paciente', $paciente->id_paciente)->with(['medico.usuario', 'especialidad']);

        return view('mis-citas.index', [
            'paciente' => $paciente,
            'proximas' => $base()->where('fecha_cita', '>=', $hoy)->orderBy('fecha_cita')->orderBy('hora_inicio')->get(),
            'anteriores' => $base()->where('fecha_cita', '<', $hoy)->orderByDesc('fecha_cita')->orderByDesc('hora_inicio')
                ->paginate(10, ['*'], 'pagina')->withQueryString(),
        ]);
    }

    public function disponibilidad(Request $request, Disponibilidad $disponibilidad): View
    {
        return view('mis-citas.disponibilidad', $this->consulta($request, $disponibilidad));
    }

    public function create(Request $request, Disponibilidad $disponibilidad): View
    {
        return view('mis-citas.create', $this->consulta($request, $disponibilidad));
    }

    public function store(SolicitudCitaRequest $request, RegistroCita $registro): RedirectResponse
    {
        $datos = $request->validated();
        [$idMedico, $hora] = $request->franjaElegida();
        $paciente = $this->paciente($request);

        $cita = $registro->registrar(
            (int) $paciente->id_paciente,
            $idMedico,
            (int) $datos['id_especialidad'],
            CarbonImmutable::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay(),
            $hora,
            $datos['motivo'] ?? null,
            $request->user()->id,
            unaPorMedicoYDia: true,
        );

        return redirect()
            ->route('mis-citas.show', $cita)
            ->with('status', "Tu cita {$cita->codigo} quedó registrada en estado «{$cita->estado}».");
    }

    public function show(Request $request, Cita $cita): View
    {
        $paciente = $this->propia($request, $cita);

        $cita->load(['medico.usuario', 'especialidad', 'historial']);

        return view('mis-citas.show', compact('cita', 'paciente'));
    }

    public function cancelar(Request $request, Cita $cita, RegistroCita $registro): RedirectResponse
    {
        $this->propia($request, $cita);

        $datos = $request->validateWithBag('cancelacion', [
            'motivo_cancelacion' => ['required', 'string', 'max:1000'],
        ], [
            'motivo_cancelacion.required' => 'Indica el motivo de la cancelación.',
            'motivo_cancelacion.max' => 'El motivo no debe superar los :max caracteres.',
        ]);

        $resultado = $registro->cancelar($cita, $datos['motivo_cancelacion'], $request->user()->id);

        return redirect()
            ->route('mis-citas.show', $cita)
            ->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status']);
    }

    public function reprogramar(Request $request, Cita $cita, Disponibilidad $disponibilidad): View|RedirectResponse
    {
        $paciente = $this->propia($request, $cita);

        if (! $cita->esCancelable() || $paciente->bloqueado) {
            return redirect()->route('mis-citas.show', $cita)->with('error', $paciente->bloqueado
                ? 'Tu cuenta tiene la solicitud de citas bloqueada: no puedes reprogramar citas. Contacta con la clínica.'
                : 'La cita ya no se puede reprogramar: solo se reprograman citas programadas o confirmadas que todavía no empezaron.');
        }

        $cita->load(['medico.usuario', 'especialidad']);

        // La especialidad es siempre la de la cita; la fecha propuesta, la que tiene ahora.
        $request->query->set('especialidad', (string) $cita->id_especialidad);
        if (! $request->query->has('fecha')) {
            $request->query->set('fecha', substr($cita->fecha_cita, 0, 10));
        }
        $consulta = DisponibilidadController::validarConsulta($request);

        return view('mis-citas.reprogramar', [
            'cita' => $cita,
            'especialidades' => collect([$cita->especialidad]),
            'medicos' => $disponibilidad->medicosActivosDe((int) $cita->id_especialidad),
            'consulta' => $consulta,
            // Solo franjas libres: nunca datos de las citas de otros pacientes.
            'resultados' => $consulta['valida'] ? $disponibilidad->porEspecialidad($cita->especialidad, $consulta['fecha'], $consulta['medico'] ?: null) : null,
        ]);
    }

    public function guardarReprogramacion(ReprogramacionCitaRequest $request, Cita $cita, RegistroCita $registro): RedirectResponse
    {
        $this->propia($request, $cita);

        $datos = $request->validated();
        [$idMedico, $hora] = $request->franjaElegida();

        $registro->reprogramar(
            $cita,
            $idMedico,
            CarbonImmutable::createFromFormat('Y-m-d', $datos['fecha'])->startOfDay(),
            $hora,
            $datos['motivo_reprogramacion'],
            $request->user()->id,
            unaPorMedicoYDia: true,
        );

        return redirect()
            ->route('mis-citas.show', $cita)
            ->with('status', "Tu cita {$cita->codigo} se reprogramó y quedó en estado «" . Cita::PROGRAMADA . '».');
    }

    /**
     * Solo sus propias citas: la de otro paciente responde como si no existiera.
     */
    private function propia(Request $request, Cita $cita): Paciente
    {
        $paciente = $this->paciente($request);
        abort_unless((int) $cita->id_paciente === (int) $paciente->id_paciente, 404);

        return $paciente;
    }

    /**
     * Datos comunes de la consulta de disponibilidad (misma validación que la del personal).
     *
     * @return array<string, mixed>
     */
    private function consulta(Request $request, Disponibilidad $disponibilidad): array
    {
        $consulta = DisponibilidadController::validarConsulta($request);
        $especialidad = $consulta['valida'] ? Especialidad::find($consulta['especialidad']) : null;

        return [
            'paciente' => $this->paciente($request),
            'especialidades' => Especialidad::where('estado', Especialidad::ACTIVA)->orderBy('nombre')->get(),
            'medicos' => $consulta['especialidad'] ? $disponibilidad->medicosActivosDe($consulta['especialidad']) : collect(),
            'consulta' => $consulta,
            'especialidad' => $especialidad,
            // Solo franjas libres: nunca datos de las citas de otros pacientes.
            'resultados' => $especialidad ? $disponibilidad->porEspecialidad($especialidad, $consulta['fecha'], $consulta['medico'] ?: null) : null,
        ];
    }

    private function paciente(Request $request): Paciente
    {
        return Paciente::findOrFail($request->user()->id);
    }
}
