<?php

namespace App\Http\Controllers;

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
 * Funciones del perfil Paciente: disponibilidad médica, solicitar cita y mis citas.
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
        // Solo sus propias citas: la de otro paciente responde como si no existiera.
        abort_unless((int) $cita->id_paciente === (int) $this->paciente($request)->id_paciente, 404);

        $cita->load(['medico.usuario', 'especialidad', 'historial']);

        return view('mis-citas.show', compact('cita'));
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
