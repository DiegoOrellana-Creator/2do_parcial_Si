<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En Render la aplicación está detrás de un proxy que atiende HTTPS y reenvía la petición
        // por HTTP. Confiar en sus cabeceras X-Forwarded-* hace que los enlaces (CSS, imágenes,
        // formularios) se generen con https y que la IP registrada sea la del usuario.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'perfil' => \App\Http\Middleware\VerificarPerfil::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
