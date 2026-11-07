<?php

namespace App\Services;

use App\Models\Cita;
use App\Models\HistorialEstadoCita;
use App\Models\Paciente;
use Illuminate\Support\Facades\DB;

/**
 * Confirmación y asistencia de citas, y bloqueo de pacientes por inasistencias.
 * Cada operación devuelve ['status' => mensaje] o ['error' => mensaje]; un error no escribe nada.
 */
class AsistenciaCita
{
    /** Inasistencias con las que un paciente queda bloqueado. */
    public const INASISTENCIAS_PARA_BLOQUEO = 3;

    /**
     * @return array{status?: string, error?: string}
     */
    public function confirmar(Cita $cita, int $registradoPor): array
    {
        return $this->cambiarEstado($cita, $registradoPor, Cita::CONFIRMADA, 'El paciente confirmó su asistencia.',
            fn (Cita $fila) => $fila->puedeConfirmarse(),
            'La cita ya no se puede confirmar: solo se confirman citas programadas que todavía no empezaron.',
            fn (Cita $fila) => "Cita {$fila->codigo} confirmada.");
    }

    /**
     * @return array{status?: string, error?: string}
     */
    public function registrarAtendida(Cita $cita, int $registradoPor): array
    {
        return $this->cambiarEstado($cita, $registradoPor, Cita::ATENDIDA, 'Consulta realizada.',
            fn (Cita $fila) => $fila->puedeRegistrarAsistencia(),
            self::ASISTENCIA_NO_DISPONIBLE,
            fn (Cita $fila) => "Cita {$fila->codigo} registrada como atendida.");
    }

    /**
     * Registra la inasistencia, la suma al paciente y lo bloquea al llegar al límite.
     *
     * @return array{status?: string, error?: string}
     */
    public function registrarInasistencia(Cita $cita, int $registradoPor): array
    {
        return $this->cambiarEstado($cita, $registradoPor, Cita::NO_ASISTIO, 'El paciente no se presentó.',
            fn (Cita $fila) => $fila->puedeRegistrarAsistencia(),
            self::ASISTENCIA_NO_DISPONIBLE,
            function (Cita $fila, Paciente $paciente) use ($registradoPor) {
                $total = $paciente->inasistencia + 1;
                $bloquear = $total >= self::INASISTENCIAS_PARA_BLOQUEO && ! $paciente->bloqueado;

                $paciente->update(['inasistencia' => $total, 'bloqueado' => $paciente->bloqueado || $bloquear]);

                if ($bloquear) {
                    DB::table('bloqueo_paciente')->insert([
                        'id_paciente' => $paciente->id_paciente,
                        'fecha_bloqueo' => now(),
                        'motivo' => "Bloqueo automático por {$total} inasistencias.",
                        'inasistencias_acumuladas' => $total,
                    ]);
                    Auditoria::registrar(Auditoria::BLOQUEO, Auditoria::PACIENTES, (int) $paciente->id_paciente,
                        "Paciente «{$this->nombre($paciente)}» bloqueado por {$total} inasistencias.", $registradoPor);
                }

                app(Notificador::class)->inasistencia($fila, $total, $bloquear);

                return "Cita {$fila->codigo} registrada como «" . Cita::NO_ASISTIO . "». El paciente acumula {$total} "
                    . ($total === 1 ? 'inasistencia' : 'inasistencias') . ($bloquear ? ' y quedó bloqueado.' : '.');
            });
    }

    /**
     * Quita el bloqueo y reinicia el contador: de lo contrario, la siguiente inasistencia lo bloquearía de nuevo.
     * El historial se conserva en las citas y en `bloqueo_paciente`.
     *
     * @return array{status?: string, error?: string}
     */
    public function desbloquear(Paciente $paciente, int $registradoPor): array
    {
        return DB::transaction(function () use ($paciente, $registradoPor) {
            $fila = Paciente::with('usuario')->whereKey($paciente->id_paciente)->lockForUpdate()->first();

            if (! $fila || ! $fila->bloqueado) {
                return ['error' => 'El paciente no está bloqueado.'];
            }

            $inasistencias = $fila->inasistencia;
            $fila->update(['bloqueado' => false, 'inasistencia' => 0]);

            DB::table('bloqueo_paciente')
                ->where('id_paciente', $fila->id_paciente)
                ->whereNull('fecha_desbloqueo')
                ->update(['fecha_desbloqueo' => now()]);

            Auditoria::registrar(Auditoria::DESBLOQUEO, Auditoria::PACIENTES, (int) $fila->id_paciente,
                "Paciente «{$this->nombre($fila)}» desbloqueado. Inasistencias reiniciadas (tenía {$inasistencias}).", $registradoPor);

            if ($fila->usuario) {
                app(Notificador::class)->desbloqueo($fila->usuario);
            }

            return ['status' => 'Paciente desbloqueado. Su contador de inasistencias volvió a 0.'];
        });
    }

    /**
     * Corrige una inasistencia registrada por error: la cita pasa a «Atendida» y se descuenta del
     * contador del paciente. Si el paciente estaba bloqueado y con la corrección queda por debajo del
     * límite, se desbloquea (el bloqueo se debía a ese error). Solo el administrador (Gate
     * «citas.corregir_inasistencia»).
     *
     * @return array{status?: string, error?: string}
     */
    public function corregirInasistencia(Cita $cita, string $motivo, int $registradoPor): array
    {
        return $this->cambiarEstado($cita, $registradoPor, Cita::ATENDIDA, "Corrección de inasistencia: {$motivo}",
            fn (Cita $fila) => $fila->estado === Cita::NO_ASISTIO,
            'Solo se corrige una cita registrada como «' . Cita::NO_ASISTIO . '».',
            function (Cita $fila, Paciente $paciente) use ($registradoPor) {
                $total = max(0, $paciente->inasistencia - 1);
                $desbloquear = $paciente->bloqueado && $total < self::INASISTENCIAS_PARA_BLOQUEO;

                $paciente->update(['inasistencia' => $total, 'bloqueado' => $paciente->bloqueado && ! $desbloquear]);

                if ($desbloquear) {
                    DB::table('bloqueo_paciente')
                        ->where('id_paciente', $paciente->id_paciente)
                        ->whereNull('fecha_desbloqueo')
                        ->update(['fecha_desbloqueo' => now()]);
                    Auditoria::registrar(Auditoria::DESBLOQUEO, Auditoria::PACIENTES, (int) $paciente->id_paciente,
                        "Paciente «{$this->nombre($paciente)}» desbloqueado al corregir la inasistencia de la cita {$fila->codigo}.", $registradoPor);
                }

                app(Notificador::class)->inasistenciaCorregida($fila, $total, $desbloquear);

                return "Inasistencia corregida: la cita {$fila->codigo} quedó como «" . Cita::ATENDIDA . "». El paciente tiene {$total} "
                    . ($total === 1 ? 'inasistencia' : 'inasistencias') . ($desbloquear ? ' y quedó desbloqueado.' : '.');
            });
    }

    private const ASISTENCIA_NO_DISPONIBLE = 'No se puede registrar la asistencia: la cita debe estar programada o confirmada y haber llegado su hora.';

    /**
     * @param  callable(Cita): bool  $permitido
     * @param  callable(Cita, Paciente): string  $alCambiar  aplica los efectos y devuelve el mensaje.
     * @return array{status?: string, error?: string}
     */
    private function cambiarEstado(Cita $cita, int $registradoPor, string $estado, string $motivo, callable $permitido, string $error, callable $alCambiar): array
    {
        return DB::transaction(function () use ($cita, $registradoPor, $estado, $motivo, $permitido, $error, $alCambiar) {
            // Mismo orden de bloqueo que al registrar y reprogramar: paciente y después cita.
            $paciente = Paciente::with('usuario')->whereKey($cita->id_paciente)->lockForUpdate()->first();
            $fila = Cita::whereKey($cita->id_cita)->lockForUpdate()->first();

            if (! $fila || ! $paciente || ! $permitido($fila)) {
                return ['error' => $error];
            }

            $anterior = $fila->estado;
            $fila->update(['estado' => $estado]);

            HistorialEstadoCita::create([
                'id_cita' => $fila->id_cita,
                'estado_anterior' => $anterior,
                'estado_nuevo' => $estado,
                'fecha_hora' => now(),
                'motivo' => $motivo,
                'registrado_por' => $registradoPor,
            ]);

            Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::CITAS, (int) $fila->id_cita,
                "Cita {$fila->codigo}: {$anterior} → {$estado}. {$motivo}", $registradoPor);

            return ['status' => $alCambiar($fila, $paciente)];
        });
    }

    private function nombre(Paciente $paciente): string
    {
        return trim(($paciente->usuario?->nombre ?? '') . ' ' . ($paciente->usuario?->apellido ?? ''));
    }
}
