<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a uno o varios perfiles de uso (ver User::perfil()).
 *
 *   ->middleware('perfil:personal')
 *
 * Complementa a los permisos: aunque un paciente o un médico recibiera por error un
 * permiso administrativo, no podría entrar a las áreas de gestión manipulando la URL.
 */
class VerificarPerfil
{
    public function handle(Request $request, Closure $next, string ...$perfiles): Response
    {
        abort_unless($request->user() && in_array($request->user()->perfil(), $perfiles, true), 403);

        return $next($request);
    }
}
