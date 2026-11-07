<?php

use App\Models\Cita;
use App\Models\Notificacion;
use App\Services\Notificador;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recordatorio a los pacientes con cita mañana (campanita y correo). No repite el aviso de una
// misma cita, así que se puede ejecutar más de una vez al día.
Artisan::command('citas:recordatorios', function (Notificador $notificador) {
    $manana = now()->addDay()->toDateString();
    $enviados = 0;

    Cita::with(['paciente.usuario', 'medico.usuario', 'especialidad'])
        ->where('fecha_cita', $manana)
        ->whereIn('estado', Cita::ESTADOS_CANCELABLES)
        ->orderBy('hora_inicio')
        ->get()
        ->each(function (Cita $cita) use ($notificador, &$enviados) {
            $yaAvisada = Notificacion::where('id_usuario', $cita->id_paciente)
                ->where('tipo', Notificacion::RECORDATORIO)
                ->where('canal', Notificacion::SISTEMA)
                ->where('mensaje', 'like', "%{$cita->codigo}%")
                ->exists();

            if (! $yaAvisada) {
                $notificador->recordatorio($cita);
                $enviados++;
            }
        });

    $this->info("Recordatorios enviados para el {$manana}: {$enviados}.");
})->purpose('Envía el recordatorio de las citas de mañana');

// Todos los días a las 08:00 (hora de la clínica). En local: php artisan schedule:work.
Schedule::command('citas:recordatorios')->dailyAt('08:00');
