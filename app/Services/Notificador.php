<?php

namespace App\Services;

use App\Mail\AvisoClinica;
use App\Models\Cita;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos a pacientes y médicos: un aviso en la campanita (canal «Sistema») y, para el paciente, un
 * correo (canal «Correo») con el resultado del envío. Todo ocurre después de confirmar la
 * transacción: si la operación se deshace, no se avisa. Un fallo del correo nunca deshace la operación.
 */
class Notificador
{
    public function citaGenerada(Cita $cita, ?Pago $pago): void
    {
        $this->paraCita($cita, Notificacion::CITA_GENERADA, 'Tu cita quedó confirmada',
            "Tu cita {$cita->codigo} quedó registrada y confirmada.",
            ['Recibimos tu pago y tu cita quedó registrada. Te esperamos 10 minutos antes de la hora.'],
            $pago ? ['Importe pagado' => $this->bs((float) $pago->monto), 'Método de pago' => (string) $pago->metodo_de_pago, 'N.º de comprobante' => (string) $pago->id_pago] : [],
            "Nueva cita {$cita->codigo} en tu agenda: {$this->horario($cita)} con {$this->nombre($cita->paciente?->usuario)}.");
    }

    public function citaCancelada(Cita $cita, string $motivo, bool $pagada): void
    {
        $this->paraCita($cita, Notificacion::CITA_CANCELADA, 'Tu cita fue cancelada',
            "Tu cita {$cita->codigo} del {$this->horario($cita)} fue cancelada.",
            array_filter(["La cita {$cita->codigo} fue cancelada. Motivo: {$motivo}",
                $pagada ? 'La cita estaba pagada: consulta en recepción por la devolución.' : null]),
            [],
            "Se canceló la cita {$cita->codigo} del {$this->horario($cita)}.");
    }

    public function citaReprogramada(Cita $cita, string $antes): void
    {
        $this->paraCita($cita, Notificacion::CITA_REPROGRAMADA, 'Tu cita cambió de horario',
            "Tu cita {$cita->codigo} se movió al {$this->horario($cita)}.",
            ["Tu cita {$cita->codigo} cambió de horario. Antes: {$antes}."],
            [],
            "La cita {$cita->codigo} se reprogramó al {$this->horario($cita)}.");
    }

    public function recordatorio(Cita $cita): void
    {
        $this->paraCita($cita, Notificacion::RECORDATORIO, 'Recordatorio de tu cita',
            "Recordatorio: mañana tienes la cita {$cita->codigo} a las " . substr($cita->hora_inicio, 0, 5) . '.',
            ['Te recordamos tu cita de mañana. Si no puedes asistir, cancélala o reprógramala desde «Mis citas».'],
            [], null);
    }

    public function inasistencia(Cita $cita, int $total, bool $bloqueado): void
    {
        $limite = AsistenciaCita::INASISTENCIAS_PARA_BLOQUEO;
        $this->paraCita($cita, $bloqueado ? Notificacion::BLOQUEO : Notificacion::INASISTENCIA,
            $bloqueado ? 'Tu cuenta fue bloqueada' : 'Registramos una inasistencia',
            $bloqueado
                ? "No asististe a la cita {$cita->codigo}. Con {$total} inasistencias tu cuenta quedó bloqueada para pedir citas."
                : "No asististe a la cita {$cita->codigo}. Llevas {$total} de {$limite} inasistencias permitidas.",
            $bloqueado
                ? ["No asististe a la cita {$cita->codigo} y acumulaste {$total} inasistencias.", 'Ya no puedes solicitar citas en línea. Acércate a la clínica para que la administración revise tu caso.']
                : ["No asististe a la cita {$cita->codigo}. Llevas {$total} de {$limite} inasistencias: al llegar a {$limite}, tu cuenta se bloquea para pedir citas."],
            [], null);
    }

    public function desbloqueo(User $paciente): void
    {
        $this->avisar($paciente, Notificacion::DESBLOQUEO, 'Tu cuenta fue desbloqueada',
            'Tu cuenta fue desbloqueada: ya puedes volver a solicitar citas.',
            ['La administración desbloqueó tu cuenta. Ya puedes volver a solicitar citas desde «Solicitar cita».'],
            [], route('mis-citas.create'), 'Solicitar una cita');
    }

    /**
     * Aviso al paciente de la cita (campanita y correo) y, si se indica, al médico (solo campanita).
     *
     * @param  list<string>  $lineas
     * @param  array<string, string>  $extra
     */
    private function paraCita(Cita $cita, string $tipo, string $asunto, string $mensaje, array $lineas, array $extra, ?string $mensajeMedico): void
    {
        $cita->loadMissing(['paciente.usuario', 'medico.usuario', 'especialidad']);
        $datos = [
            'Cita' => $cita->codigo,
            'Fecha' => Carbon::parse($cita->fecha_cita)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'),
            'Hora' => substr($cita->hora_inicio, 0, 5) . '–' . substr($cita->hora_fin, 0, 5),
            'Médico' => $this->nombre($cita->medico?->usuario),
            'Especialidad' => (string) $cita->especialidad?->nombre,
            ...$extra,
        ];

        if ($cita->paciente?->usuario) {
            $this->avisar($cita->paciente->usuario, $tipo, $asunto, $mensaje, $lineas, $datos, route('mis-citas.index'), 'Ver mis citas');
        }
        if ($mensajeMedico && $cita->medico?->usuario) {
            $this->avisar($cita->medico->usuario, $tipo, $asunto, $mensajeMedico, [], [], null, null, conCorreo: false);
        }
    }

    /**
     * @param  list<string>  $lineas
     * @param  array<string, string>  $datos
     */
    private function avisar(User $usuario, string $tipo, string $asunto, string $mensaje, array $lineas, array $datos,
        ?string $enlace, ?string $textoEnlace, bool $conCorreo = true): void
    {
        DB::afterCommit(function () use ($usuario, $tipo, $asunto, $mensaje, $lineas, $datos, $enlace, $textoEnlace, $conCorreo) {
            $ahora = now();
            Notificacion::create([
                'id_usuario' => $usuario->id, 'tipo' => $tipo, 'mensaje' => $mensaje, 'canal' => Notificacion::SISTEMA,
                'estado_envio' => Notificacion::ENVIADO, 'fecha_envio' => $ahora, 'leido' => false,
            ]);

            if (! $conCorreo || blank($usuario->email)) {
                return;
            }

            $saludo = 'Hola, ' . trim((string) $usuario->nombre) . '.';
            try {
                Mail::to($usuario->email)->send(new AvisoClinica($asunto, $saludo, $lineas ?: [$mensaje], $datos, $enlace, $textoEnlace));
                $estado = Notificacion::ENVIADO;
            } catch (Throwable $e) {
                Log::warning("No se pudo enviar el correo «{$tipo}» al usuario {$usuario->id}: " . $e->getMessage());
                $estado = Notificacion::FALLIDO;
            }

            Notificacion::create([
                'id_usuario' => $usuario->id, 'tipo' => $tipo, 'mensaje' => $mensaje, 'canal' => Notificacion::CORREO,
                'estado_envio' => $estado, 'fecha_envio' => $estado === Notificacion::ENVIADO ? $ahora : null, 'leido' => true,
            ]);
        });
    }

    private function horario(Cita $cita): string
    {
        return Carbon::parse($cita->fecha_cita)->format('d/m/Y') . ' a las ' . substr($cita->hora_inicio, 0, 5);
    }

    private function nombre(?User $usuario): string
    {
        return trim(($usuario?->nombre ?? '') . ' ' . ($usuario?->apellido ?? ''));
    }

    private function bs(float $monto): string
    {
        return 'Bs ' . number_format($monto, 2, ',', '.');
    }
}
