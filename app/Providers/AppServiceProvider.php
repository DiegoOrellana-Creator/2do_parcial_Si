<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Permisos de los módulos: se conceden según la tabla `permiso`
        // a través de los roles activos del usuario (ver User::tienePermiso).
        $permisos = [
            'pacientes.ver', 'pacientes.crear', 'pacientes.editar',
            'especialidades.ver', 'especialidades.crear', 'especialidades.editar', 'especialidades.eliminar',
            'medicos.ver', 'medicos.crear', 'medicos.editar',
            'horarios.ver', 'horarios.crear', 'horarios.editar', 'horarios.eliminar',
            'disponibilidad.ver',
            'citas.ver', 'citas.crear', 'citas.cancelar',
            'agenda.ver',
        ];

        foreach ($permisos as $codigo) {
            Gate::define($codigo, fn (User $user) => $user->tienePermiso($codigo));
        }

        // Reprogramar equivale a cancelar una hora y registrar otra: exige ambos permisos existentes.
        Gate::define('citas.reprogramar', fn (User $user) => $user->tienePermiso('citas.crear') && $user->tienePermiso('citas.cancelar'));

        // Confirmar citas y registrar asistencia: quien gestiona el estado de las citas.
        Gate::define('citas.asistencia', fn (User $user) => $user->tienePermiso('citas.cancelar'));

        // Bloquear la agenda de un médico: quien gestiona las citas.
        Gate::define('agenda.bloquear', fn (User $user) => $user->tienePermiso('citas.cancelar'));

        // Desbloquear a un paciente: exclusivo del administrador.
        Gate::define('pacientes.desbloquear', fn (User $user) => $user->esAdministrador());

        // Bitácora: exclusiva del administrador (no depende de filas en `permiso`).
        Gate::define('bitacora.ver', fn (User $user) => $user->esAdministrador());

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            return (new MailMessage)
                ->subject('Recuperar Contraseña - Clínica Santa Cruz')
                ->greeting('¡Hola ' . $notifiable->nombre . '!')
                ->line('Estás recibiendo este correo porque solicitaste restablecer tu contraseña.')
                ->action('Restablecer Contraseña', url(route('password.reset', [
                    'token' => $token,
                    'email' => $notifiable->getEmailForPasswordReset(),
                ], false)))
                ->line('Si no solicitaste este cambio, ignora este mensaje de forma segura.')
                ->salutation('Saludos, Administración Clínica Santa Cruz');
        });
    }
}