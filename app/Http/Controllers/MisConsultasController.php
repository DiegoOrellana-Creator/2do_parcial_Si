<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Services\AsistenciaCita;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Mis citas» del médico: sus propias citas. Las rutas exigen el perfil «medico» y el médico es
 * siempre la cuenta autenticada. Lo único que modifica es la asistencia (atendida o no asistió),
 * con las mismas reglas que la recepción; no cancela, reprograma ni cobra.
 */
class MisConsultasController extends Controller
{
    public function index(Request $request): View
    {
        $idMedico = $request->user()->id;
        $hoy = now()->toDateString();
        $estado = in_array($request->query('estado'), Cita::ESTADOS, true) ? $request->query('estado') : '';

        $base = fn () => Cita::where('id_medico', $idMedico)
            ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
            ->with(['paciente.usuario', 'especialidad']);

        return view('mis-consultas.index', [
            'estado' => $estado,
            'proximas' => $base()->where('fecha_cita', '>=', $hoy)->orderBy('fecha_cita')->orderBy('hora_inicio')
                ->paginate(15, ['*'], 'proximas')->withQueryString(),
            'anteriores' => $base()->where('fecha_cita', '<', $hoy)->orderByDesc('fecha_cita')->orderByDesc('hora_inicio')
                ->paginate(15, ['*'], 'anteriores')->withQueryString(),
        ]);
    }

    public function show(Request $request, Cita $cita): View
    {
        // Solo sus propias citas: la de otro médico responde como si no existiera.
        abort_unless((int) $cita->id_medico === (int) $request->user()->id, 404);

        $cita->load(['paciente.usuario', 'especialidad', 'historial']);

        return view('mis-consultas.show', compact('cita'));
    }

    public function atendida(Request $request, Cita $cita, AsistenciaCita $asistencia): RedirectResponse
    {
        $this->propia($request, $cita);

        return $this->volver($cita, $asistencia->registrarAtendida($cita, $request->user()->id));
    }

    public function inasistencia(Request $request, Cita $cita, AsistenciaCita $asistencia): RedirectResponse
    {
        $this->propia($request, $cita);

        return $this->volver($cita, $asistencia->registrarInasistencia($cita, $request->user()->id));
    }

    /** Solo sus propias citas: la de otro médico responde como si no existiera. */
    private function propia(Request $request, Cita $cita): void
    {
        abort_unless((int) $cita->id_medico === (int) $request->user()->id, 404);
    }

    /** @param  array{status?: string, error?: string}  $resultado */
    private function volver(Cita $cita, array $resultado): RedirectResponse
    {
        return redirect()->route('consultas.show', $cita)
            ->with(isset($resultado['error']) ? 'error' : 'status', $resultado['error'] ?? $resultado['status']);
    }
}
