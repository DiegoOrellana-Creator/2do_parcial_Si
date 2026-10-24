<?php

namespace App\Services;

use App\Models\Bitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de acciones en la bitácora.
 *
 * - Se llama desde el servidor después de que la operación tuvo éxito.
 * - Si se llama dentro de una transacción, la fila se escribe solo cuando esa transacción
 *   se confirma: una operación revertida no deja registro.
 * - El detalle lo compone la aplicación; nunca incluye contraseñas, hashes ni tokens.
 */
class Auditoria
{
    // Módulos
    public const SESION = 'Sesión';

    public const CUENTA = 'Cuenta';

    public const PACIENTES = 'Pacientes';

    public const MEDICOS = 'Médicos';

    public const ESPECIALIDADES = 'Especialidades';

    public const HORARIOS = 'Horarios de atención';

    public const CITAS = 'Citas';

    // Acciones
    public const INICIO_SESION = 'Inicio de sesión';

    public const INICIO_FALLIDO = 'Inicio de sesión fallido';

    public const CIERRE_SESION = 'Cierre de sesión';

    public const CREAR = 'Creación';

    public const MODIFICAR = 'Modificación';

    public const ELIMINAR = 'Eliminación';

    public const CAMBIO_ESTADO = 'Cambio de estado';

    public const CAMBIO_CONTRASENA = 'Cambio de contraseña';

    /** Campos que nunca se nombran ni se guardan en el detalle. */
    private const CAMPOS_SENSIBLES = ['password', 'remember_token', 'updated_at', 'created_at'];

    /**
     * @param  ?int  $idUsuario  autor; por defecto, la cuenta autenticada.
     * @param  bool  $sinUsuario  registrar sin autor (por ejemplo, cuando la cuenta acaba de eliminarse).
     */
    public static function registrar(string $accion, string $modulo, ?int $idRegistro = null, ?string $detalle = null,
        ?int $idUsuario = null, bool $sinUsuario = false): void
    {
        $fila = [
            'id_usuario' => $sinUsuario ? null : ($idUsuario ?? Auth::id()),
            'fecha_hora' => now(),
            'accion' => Str::limit($accion, 100, ''),
            'modulo' => Str::limit($modulo, 100, ''),
            'id_registro' => $idRegistro,
            'detalle' => $detalle !== null ? Str::limit($detalle, 1000) : null,
            'ip' => request()?->ip(),
        ];

        DB::afterCommit(fn () => Bitacora::create($fila));
    }

    /**
     * Nombres de los campos que cambiaron en un modelo recién actualizado (sin valores ni
     * campos sensibles), para describir una modificación.
     */
    public static function camposCambiados(Model ...$modelos): ?string
    {
        $campos = collect($modelos)
            ->flatMap(fn (Model $m) => array_keys($m->getChanges()))
            ->reject(fn (string $campo) => in_array($campo, self::CAMPOS_SENSIBLES, true))
            ->unique()
            ->map(fn (string $campo) => str_replace('_', ' ', $campo))
            ->values();

        return $campos->isEmpty() ? null : 'Campos modificados: ' . $campos->join(', ') . '.';
    }
}
