<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $request->user()->save();

        if ($request->user()->wasChanged()) {
            Auditoria::registrar(Auditoria::MODIFICAR, Auditoria::CUENTA, $request->user()->id, trim('Datos de «Mi perfil». ' . Auditoria::camposCambiados($request->user())));
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
