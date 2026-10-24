<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            return (new MailMessage)
                ->subject('Recuperar Contraseña - Clínica Santa Cruz')
                ->greeting('¡Hola ' . $notifiable->name . '!')
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