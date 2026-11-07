<?php

namespace App\Rules;

use App\Services\DependenciasUsuario;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * «admin» identifica a la cuenta principal (DependenciasUsuario): ninguna otra cuenta puede
 * tomar ese nombre de usuario, sin distinguir mayúsculas. La cuenta que ya lo tiene lo conserva.
 */
class UsuarioNoReservado implements ValidationRule
{
    public function __construct(private readonly ?int $idUsuario = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (mb_strtolower((string) $value) !== DependenciasUsuario::CUENTA_PRINCIPAL_USERNAME) {
            return;
        }

        $actual = $this->idUsuario ? DB::table('users')->where('id', $this->idUsuario)->value('username') : null;

        if ($actual !== $value) {
            $fail('Ese nombre de usuario está reservado.');
        }
    }
}
