<?php

namespace App\Services;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HistorialEstadoCita;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Pago;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro y reprogramación de citas con todas las reglas de negocio. Lo usan la recepción
 * (Gestión de citas) y el propio paciente (Solicitar cita), para que apliquen exactamente lo mismo.
 * Una cita nueva primero se cobra: registrar() crea una reserva «Pendiente de pago» que aparta la
 * franja unos minutos, y el pago (Pagos\RegistroPago) la convierte en la cita.
 */
class RegistroCita
{
    public function __construct(private Disponibilidad $disponibilidad) {}

    /**
     * Reserva la franja hasta que se pague (Cita::PENDIENTE_PAGO). La especialidad debe tener precio.
     *
     * @param  bool  $unaPorMedicoYDia  además, rechaza una segunda cita pendiente del paciente con
     *                                  el mismo médico el mismo día (solicitudes del paciente).
     *
     * @throws ValidationException si alguna regla no se cumple; no se escribe nada.
     */
    public function registrar(int $idPaciente, int $idMedico, int $idEspecialidad, CarbonImmutable $fecha, string $hora,
        ?string $motivo, int $registradoPor, bool $unaPorMedicoYDia = false): Cita
    {
        $this->anularReservasVencidas();

        return DB::transaction(function () use ($idPaciente, $idMedico, $idEspecialidad, $fecha, $hora, $motivo, $registradoPor, $unaPorMedicoYDia) {
            // Bloqueo en orden fijo (médico, luego paciente): dos registros simultáneos para el
            // mismo médico o el mismo paciente se ejecutan uno después del otro.
            $medico = Medico::with('usuario')->whereKey($idMedico)->lockForUpdate()->first();
            $paciente = Paciente::with('usuario')->whereKey($idPaciente)->lockForUpdate()->first();
            $especialidad = Especialidad::find($idEspecialidad);

            $fin = $this->comprobarReglas($medico, $paciente, $especialidad, $idMedico, $fecha, $hora);

            if ($especialidad->precio === null || (float) $especialidad->precio <= 0) {
                throw ValidationException::withMessages(['id_especialidad' => 'La especialidad no tiene precio registrado: no se puede cobrar la cita.']);
            }

            if ($unaPorMedicoYDia) {
                $this->comprobarDuplicada((int) $paciente->id_paciente, $idMedico, $fecha);
            }

            $ahora = now();
            $cita = Cita::create([
                'codigo' => $this->nuevoCodigo($fecha),
                'id_paciente' => $paciente->id_paciente,
                'id_medico' => $idMedico,
                'id_especialidad' => $especialidad->id_especialidad,
                'fecha_cita' => $fecha->toDateString(),
                'hora_inicio' => $hora . ':00',
                'hora_fin' => $fin . ':00',
                'motivo' => $motivo,
                'estado' => Cita::PENDIENTE_PAGO,
                'fecha_creacion' => $ahora,
            ]);

            HistorialEstadoCita::create([
                'id_cita' => $cita->id_cita,
                'estado_anterior' => null,
                'estado_nuevo' => Cita::PENDIENTE_PAGO,
                'fecha_hora' => $ahora,
                'motivo' => 'Horario reservado: pendiente de pago.',
                'registrado_por' => $registradoPor,
            ]);

            $origen = $registradoPor === (int) $paciente->id_paciente ? 'solicitada por el propio paciente' : 'registrada por el personal';
            Auditoria::registrar(Auditoria::CREAR, Auditoria::CITAS, (int) $cita->id_cita,
                "Reserva {$cita->codigo} pendiente de pago, {$origen}: {$fecha->format('d/m/Y')} {$hora}–{$fin}, {$especialidad->nombre}, "
                . "paciente «{$paciente->usuario->nombre} {$paciente->usuario->apellido}», médico «{$medico->usuario->nombre} {$medico->usuario->apellido}».", $registradoPor);

            return $cita;
        });
    }

    /**
     * Mueve una cita pendiente a otra franja de la misma especialidad, con el mismo u otro médico.
     * Conserva su código y su paciente, y vuelve a «Programada»: una confirmación valía para la hora anterior.
     *
     * @param  bool  $unaPorMedicoYDia  como al solicitar: sin otra cita pendiente con ese médico ese día.
     *
     * @throws ValidationException si alguna regla no se cumple; no se escribe nada.
     */
    public function reprogramar(Cita $cita, int $idMedico, CarbonImmutable $fecha, string $hora, string $motivo, int $registradoPor, bool $unaPorMedicoYDia = false): Cita
    {
        return DB::transaction(function () use ($cita, $idMedico, $fecha, $hora, $motivo, $registradoPor, $unaPorMedicoYDia) {
            // Mismo orden de bloqueo que al registrar; después, la cita (como al cancelarla).
            $medico = Medico::with('usuario')->whereKey($idMedico)->lockForUpdate()->first();
            $paciente = Paciente::with('usuario')->whereKey($cita->id_paciente)->lockForUpdate()->first();
            $fila = Cita::with(['medico.usuario', 'especialidad'])->whereKey($cita->id_cita)->lockForUpdate()->first();

            if (! $fila || ! $fila->esCancelable()) {
                throw ValidationException::withMessages(['franja' => 'La cita ya no se puede reprogramar: solo se reprograman citas programadas o confirmadas que todavía no empezaron.']);
            }

            $fin = $this->comprobarReglas($medico, $paciente, $fila->especialidad, $idMedico, $fecha, $hora, (int) $fila->id_cita);

            if ($unaPorMedicoYDia) {
                $this->comprobarDuplicada((int) $paciente->id_paciente, $idMedico, $fecha, (int) $fila->id_cita);
            }

            $antes = $this->describirHorario($fila);
            $estadoAnterior = $fila->estado;
            $fila->update([
                'id_medico' => $idMedico,
                'fecha_cita' => $fecha->toDateString(),
                'hora_inicio' => $hora . ':00',
                'hora_fin' => $fin . ':00',
                'estado' => Cita::PROGRAMADA,
            ]);
            $fila->setRelation('medico', $medico);
            $cambio = "De {$antes} a {$this->describirHorario($fila)}. Motivo: {$motivo}";

            HistorialEstadoCita::create([
                'id_cita' => $fila->id_cita,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => Cita::PROGRAMADA,
                'fecha_hora' => now(),
                'motivo' => "Cita reprogramada. {$cambio}",
                'registrado_por' => $registradoPor,
            ]);

            Auditoria::registrar(Auditoria::REPROGRAMAR, Auditoria::CITAS, (int) $fila->id_cita, "Cita {$fila->codigo}. {$cambio}", $registradoPor);

            return $fila;
        });
    }

    /**
     * Cancela una cita que sigue vigente y todavía no empezó. La cita se conserva con su historial.
     *
     * @return array{status?: string, error?: string} un error no escribe nada.
     */
    public function cancelar(Cita $cita, string $motivo, int $canceladoPor): array
    {
        return DB::transaction(function () use ($cita, $motivo, $canceladoPor) {
            $fila = Cita::whereKey($cita->id_cita)->lockForUpdate()->first();

            if (! $fila->esCancelable()) {
                return ['error' => 'La cita ya no se puede cancelar: solo se cancelan citas programadas o confirmadas que todavía no empezaron.'];
            }

            $ahora = now();
            $anterior = $fila->estado;
            $fila->update([
                'estado' => Cita::CANCELADA,
                'motivo_cancelacion' => $motivo,
                'fecha_cancelacion' => $ahora,
                'cancelado_por' => $canceladoPor,
            ]);

            HistorialEstadoCita::create([
                'id_cita' => $fila->id_cita,
                'estado_anterior' => $anterior,
                'estado_nuevo' => Cita::CANCELADA,
                'fecha_hora' => $ahora,
                'motivo' => $motivo,
                'registrado_por' => $canceladoPor,
            ]);

            Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::CITAS, (int) $fila->id_cita,
                "Cita {$fila->codigo}: {$anterior} → " . Cita::CANCELADA . ". Motivo: {$motivo}", $canceladoPor);

            return ['status' => "Cita {$fila->codigo} cancelada. La franja vuelve a estar disponible."];
        });
    }

    /**
     * Anula una reserva que todavía no se pagó: la franja vuelve a quedar libre. No es una cita,
     * así que no se «cancela»: queda como «Reserva anulada» para el registro.
     *
     * @return array{status?: string, error?: string}
     */
    public function anularReserva(Cita $reserva, string $motivo, ?int $registradoPor): array
    {
        return DB::transaction(function () use ($reserva, $motivo, $registradoPor) {
            $fila = Cita::conReservas()->whereKey($reserva->id_cita)->lockForUpdate()->first();

            if (! $fila?->esReserva()) {
                return ['error' => 'La reserva ya no está pendiente de pago.'];
            }
            if ($fila->estaPagada()) {
                return ['error' => 'La reserva ya está pagada.'];
            }

            $this->marcarAnulada($fila, $motivo, $registradoPor);

            return ['status' => 'Reserva anulada: el horario vuelve a estar disponible y no se realizó ningún cobro.'];
        });
    }

    /**
     * Las reservas cuyo plazo de pago pasó quedan anuladas (sus intentos de pago, como fallidos).
     * Disponibilidad ya no las cuenta aunque no se llame a este método: aquí solo se dejan en orden.
     */
    public function anularReservasVencidas(): int
    {
        $vencidas = Cita::conReservas()->where('estado', Cita::PENDIENTE_PAGO)
            ->where('fecha_creacion', '<=', now()->subMinutes(Cita::minutosReserva()))
            ->whereDoesntHave('pagos', fn ($q) => $q->where('estado_pago', Pago::PAGADO))
            ->get();

        foreach ($vencidas as $reserva) {
            DB::transaction(fn () => $this->marcarAnulada($reserva, 'El plazo para pagar venció.', null));
        }

        return $vencidas->count();
    }

    private function marcarAnulada(Cita $reserva, string $motivo, ?int $registradoPor): void
    {
        $reserva->update(['estado' => Cita::RESERVA_ANULADA]);
        Pago::where('id_cita', $reserva->id_cita)->where('estado_pago', Pago::PENDIENTE)->update(['estado_pago' => Pago::FALLIDO]);

        HistorialEstadoCita::create([
            'id_cita' => $reserva->id_cita,
            'estado_anterior' => Cita::PENDIENTE_PAGO,
            'estado_nuevo' => Cita::RESERVA_ANULADA,
            'fecha_hora' => now(),
            'motivo' => $motivo,
            'registrado_por' => $registradoPor,
        ]);
        Auditoria::registrar(Auditoria::CAMBIO_ESTADO, Auditoria::CITAS, (int) $reserva->id_cita,
            "Reserva {$reserva->codigo} anulada sin pago. {$motivo}", $registradoPor);
    }

    /**
     * @throws ValidationException si el paciente ya tiene otra cita pendiente con ese médico ese día.
     */
    private function comprobarDuplicada(int $idPaciente, int $idMedico, CarbonImmutable $fecha, ?int $exceptoCita = null): void
    {
        $duplicada = Cita::soloQueOcupan(Cita::conReservas()->where('id_paciente', $idPaciente))
            ->when($exceptoCita, fn ($q) => $q->where('id_cita', '<>', $exceptoCita))
            ->where('id_medico', $idMedico)
            ->where('fecha_cita', $fecha->toDateString())
            ->whereIn('estado', [...Cita::ESTADOS_CANCELABLES, Cita::PENDIENTE_PAGO])
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages(['franja' => 'Ya tienes una cita pendiente con ese médico ese día.']);
        }
    }

    /**
     * Reglas comunes de registrar y reprogramar. Devuelve la hora de fin de la franja.
     *
     * @param  ?int  $exceptoCita  cita que se está moviendo: no choca consigo misma en el paciente.
     *
     * @throws ValidationException
     */
    private function comprobarReglas(?Medico $medico, ?Paciente $paciente, ?Especialidad $especialidad, int $idMedico, CarbonImmutable $fecha, string $hora, ?int $exceptoCita = null): string
    {
        $falla = fn (string $campo, string $mensaje) => throw ValidationException::withMessages([$campo => $mensaje]);

        if (! $especialidad || $especialidad->estado !== Especialidad::ACTIVA) {
            $falla('id_especialidad', 'La especialidad no está activa.');
        }
        if (! $medico || $medico->usuario?->estado !== User::ESTADO_ACTIVO) {
            $falla('franja', 'El médico seleccionado no existe o no está activo.');
        }
        if (! DB::table('especialidad_medico')->where('id_medico', $idMedico)->where('id_especialidad', $especialidad->id_especialidad)->exists()) {
            $falla('franja', 'El médico seleccionado no ejerce esa especialidad.');
        }
        if (! $paciente || $paciente->usuario?->estado !== User::ESTADO_ACTIVO) {
            $falla('id_paciente', 'La cuenta del paciente no está activa.');
        }
        if ($paciente->bloqueado) {
            $falla('id_paciente', 'El paciente está bloqueado y no puede registrar citas.');
        }
        if ((int) $paciente->id_paciente === (int) $medico->id_medico) {
            $falla('id_paciente', 'Un médico no puede tener una cita consigo mismo.');
        }

        [$libre, $fin, $motivoNoLibre] = $this->disponibilidad->comprobarFranja($idMedico, $fecha, $hora);
        if (! $libre) {
            $falla('franja', $motivoNoLibre);
        }

        // También cuenta una reserva suya que todavía espera el pago.
        $pacienteOcupado = Cita::soloQueOcupan(Cita::conReservas()->where('id_paciente', $paciente->id_paciente))
            ->when($exceptoCita, fn ($q) => $q->where('id_cita', '<>', $exceptoCita))
            ->where('fecha_cita', $fecha->toDateString())
            ->where('hora_inicio', '<', $fin . ':00')
            ->where('hora_fin', '>', $hora . ':00')
            ->exists();
        if ($pacienteOcupado) {
            $falla('franja', 'El paciente ya tiene otra cita a esa hora.');
        }

        return $fin;
    }

    /** Ejemplo: «09/11/2026 08:00–08:30 con Carlos Méndez». */
    private function describirHorario(Cita $cita): string
    {
        $medico = $cita->medico->usuario;

        return CarbonImmutable::parse($cita->fecha_cita)->format('d/m/Y') . ' ' . substr($cita->hora_inicio, 0, 5) . '–' . substr($cita->hora_fin, 0, 5)
            . " con {$medico->nombre} {$medico->apellido}";
    }

    private function nuevoCodigo(CarbonImmutable $fecha): string
    {
        do {
            $codigo = 'CIT-' . $fecha->format('Ymd') . '-' . Str::upper(Str::random(5));
        } while (Cita::conReservas()->where('codigo', $codigo)->exists());

        return $codigo;
    }
}
