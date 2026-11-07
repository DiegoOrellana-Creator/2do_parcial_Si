<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Avisos del usuario autenticado (campanita). Cada usuario ve solo los suyos.
 */
class NotificacionController extends Controller
{
    public function index(Request $request): View
    {
        $notificaciones = Notificacion::query()->de((int) $request->user()->id)
            ->orderByDesc('fecha_envio')->orderByDesc('id_notificacion')
            ->paginate(15);

        return view('notificaciones.index', [
            'notificaciones' => $notificaciones,
            'sinLeer' => Notificacion::query()->de((int) $request->user()->id)->where('leido', false)->count(),
        ]);
    }

    public function marcarLeidas(Request $request): RedirectResponse
    {
        Notificacion::query()->de((int) $request->user()->id)->where('leido', false)->update(['leido' => true]);

        return redirect()->route('notificaciones.index')->with('status', 'Todas las notificaciones quedaron como leídas.');
    }
}
