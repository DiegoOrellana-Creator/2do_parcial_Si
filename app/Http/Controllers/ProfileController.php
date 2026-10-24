<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\DependenciasUsuario;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($request->user()->wasChanged()) {
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::CUENTA, $request->user()->id, trim('Datos de «Mi perfil». ' . Auditoria::camposCambiados($request->user())));
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            // Borrar el usuario arrastra en cascada su ficha de paciente, sus citas (con pagos
            // e historial), sus datos de médico y sus roles. Solo se permite si la cuenta no
            // tiene ningún vínculo clínico ni administrativo; se comprueba con la fila bloqueada.
            $impedimentos = DB::transaction(function () use ($user) {
                User::whereKey($user->id)->lockForUpdate()->first();

                $impedimentos = (new DependenciasUsuario((int) $user->id))->impedimentosParaEliminarCuenta();

                if (! $impedimentos) {
                    $user->delete();
                }

                return $impedimentos;
            });
        } catch (QueryException $e) {
            // 23503: la base de datos rechazó el borrado por una clave foránea (protección RESTRICT).
            if ($e->getCode() !== '23503') {
                throw $e;
            }

            $impedimentos = ['tiene registros relacionados en la base de datos'];
        }

        if ($impedimentos) {
            return Redirect::route('profile.edit')->withErrors([
                'cuenta' => 'No puedes eliminar tu cuenta porque ' . implode(' y ', $impedimentos)
                    . '. Solicita la baja al personal de la clínica.',
            ], 'userDeletion');
        }

        // La cuenta ya no existe: el registro queda sin autor y conserva el usuario como referencia.
        Auditoria::registrar(Auditoria::ELIMINAR, Auditoria::CUENTA, (int) $user->id, "El usuario «{$user->username}» eliminó su propia cuenta.", sinUsuario: true);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
