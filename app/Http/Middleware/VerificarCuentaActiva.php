<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auditoria;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión de una cuenta que dejó de estar activa: desactivarla surte efecto en su siguiente
 * petición, no solo en su próximo inicio de sesión. El estado se lee siempre de la base de datos.
 */
class VerificarCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = Auth::id();

        if ($id === null || DB::table('users')->where('id', $id)->value('estado') === User::ESTADO_ACTIVO) {
            return $next($request);
        }

        Auditoria::registrar(Auditoria::CIERRE_SESION, Auditoria::SESION, (int) $id, 'Sesión cerrada automáticamente: la cuenta no está activa.');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'Tu sesión se cerró porque la cuenta no está activa. Contacta con la clínica.',
        ]);
    }
}
