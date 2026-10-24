<?php

namespace App\Services;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cálculo de franjas de atención a partir de las tablas reales:
 *  - horario_atencion: bloques semanales del médico (dia_semana ISO 1–7, duracion_cita en minutos);
 *  - bloqueo_agenda: intervalos en los que el médico no atiende;
 *  - citas: citas que ocupan la franja (todas salvo las canceladas).
 *
 * Lo usan Disponibilidad, Registro de citas y Agenda, para aplicar las mismas reglas.
 */
class Disponibilidad
{
    public const LIBRE = 'libre';

    public const OCUPADA = 'ocupada';

    public const BLOQUEADA = 'bloqueada';

    public const PASADA = 'pasada';

    /**
     * Todas las franjas de un médico en una fecha, con su estado.
     *
     * @return Collection<int, array{inicio: string, fin: string, estado: string, cita: ?object, horario: int}>
     */
    public function franjas(int $idMedico, CarbonImmutable $fecha): Collection
    {
        $horarios = HorarioAtencion::where('id_medico', $idMedico)
            ->where('dia_semana', $fecha->dayOfWeekIso)
            ->whereNotNull('duracion_cita')
            ->where('duracion_cita', '>', 0)
            ->orderBy('hora_inicio')
            ->get();

        if ($horarios->isEmpty()) {
            return collect();
        }

        $citas = DB::table('citas')
            ->leftJoin('users as paciente', 'paciente.id', '=', 'citas.id_paciente')
            ->where('citas.id_medico', $idMedico)
            ->where('citas.fecha_cita', $fecha->toDateString())
            ->where('citas.estado', '<>', Cita::CANCELADA)
            ->get(['citas.id_cita', 'citas.codigo', 'citas.hora_inicio', 'citas.hora_fin', 'citas.estado',
                'paciente.nombre as paciente_nombre', 'paciente.apellido as paciente_apellido']);

        $bloqueos = DB::table('bloqueo_agenda')
            ->where('id_medico', $idMedico)
            ->where('fecha', $fecha->toDateString())
            ->get(['hora_inicio', 'hora_final', 'motivo']);

        $ahora = CarbonImmutable::now();
        $franjas = collect();

        foreach ($horarios as $horario) {
            $inicio = $fecha->setTimeFromTimeString($horario->hora_inicio);
            $limite = $fecha->setTimeFromTimeString($horario->hora_final);

            while ($inicio->addMinutes($horario->duracion_cita)->lessThanOrEqualTo($limite)) {
                $fin = $inicio->addMinutes($horario->duracion_cita);
                $desde = $inicio->format('H:i:s');
                $hasta = $fin->format('H:i:s');

                $cita = $citas->first(fn ($c) => $c->hora_inicio < $hasta && $c->hora_fin > $desde);
                $bloqueo = $bloqueos->first(fn ($b) => $b->hora_inicio < $hasta && $b->hora_final > $desde);

                $estado = match (true) {
                    $cita !== null => self::OCUPADA,
                    $bloqueo !== null => self::BLOQUEADA,
                    $inicio->lessThanOrEqualTo($ahora) => self::PASADA,
                    default => self::LIBRE,
                };

                $franjas->push([
                    'inicio' => $inicio->format('H:i'),
                    'fin' => $fin->format('H:i'),
                    'estado' => $estado,
                    'cita' => $cita,
                    'bloqueo' => $bloqueo,
                    'horario' => $horario->id_horario,
                ]);

                $inicio = $fin;
            }
        }

        return $franjas->sortBy('inicio')->values();
    }

    /**
     * Médicos activos que ejercen una especialidad activa, con sus franjas libres en la fecha.
     *
     * @return Collection<int, array{medico: Medico, libres: Collection, total: int}>
     */
    public function porEspecialidad(Especialidad $especialidad, CarbonImmutable $fecha, ?int $idMedico = null): Collection
    {
        if ($especialidad->estado !== Especialidad::ACTIVA) {
            return collect();
        }

        return $this->medicosActivosDe($especialidad->id_especialidad)
            ->when($idMedico, fn ($medicos) => $medicos->where('id_medico', $idMedico))
            ->map(function (Medico $medico) use ($fecha) {
                $franjas = $this->franjas((int) $medico->id_medico, $fecha);

                return [
                    'medico' => $medico,
                    'libres' => $franjas->where('estado', self::LIBRE)->values(),
                    'total' => $franjas->count(),
                ];
            })
            ->values();
    }

    /**
     * Médicos con cuenta activa que tienen asignada la especialidad.
     *
     * @return Collection<int, Medico>
     */
    public function medicosActivosDe(int $idEspecialidad): Collection
    {
        return Medico::query()
            ->select('medico.*')
            ->join('users', 'users.id', '=', 'medico.id_medico')
            ->join('especialidad_medico', 'especialidad_medico.id_medico', '=', 'medico.id_medico')
            ->where('especialidad_medico.id_especialidad', $idEspecialidad)
            ->where('users.estado', User::ESTADO_ACTIVO)
            ->with('usuario')
            ->orderBy('users.apellido')
            ->orderBy('users.nombre')
            ->get();
    }

    /**
     * Comprueba que una franja concreta esté libre para registrar una cita.
     *
     * @return array{0: bool, 1: ?string, 2: ?string} [libre, hora_fin HH:MM, motivo si no lo está]
     */
    public function comprobarFranja(int $idMedico, CarbonImmutable $fecha, string $hora): array
    {
        $franja = $this->franjas($idMedico, $fecha)->firstWhere('inicio', $hora);

        return match ($franja['estado'] ?? null) {
            null => [false, null, 'La hora elegida no corresponde a ningún horario de atención del médico.'],
            self::LIBRE => [true, $franja['fin'], null],
            self::OCUPADA => [false, null, 'Esa franja ya está ocupada por otra cita.'],
            self::BLOQUEADA => [false, null, 'El médico tiene la agenda bloqueada en esa franja.'],
            default => [false, null, 'Esa franja ya pasó.'],
        };
    }
}
