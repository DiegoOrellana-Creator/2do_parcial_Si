<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asigna el rol Administrador a la cuenta administrativa EXISTENTE y la registra en la tabla
 * `administrador`. No crea cuentas ni modifica su usuario, correo, contraseña o estado.
 *
 * Es OPCIONAL y aparte de la población de prueba: ejecutarlo solo con autorización.
 *
 *   php artisan db:seed --class=AdministradorExistenteSeeder
 *
 * La cuenta se identifica por id y username a la vez; si no coinciden, no se escribe nada.
 */
class AdministradorExistenteSeeder extends Seeder
{
    private const ID_USUARIO = 1;

    private const USERNAME = 'admin';

    private const CODIGO_ADMINISTRADOR = 'ADM-001';

    public function run(): void
    {
        DB::transaction(function () {
            $usuario = DB::table('users')->where('id', self::ID_USUARIO)->lockForUpdate()->first();

            if (! $usuario || $usuario->username !== self::USERNAME || $usuario->estado !== 'Activo') {
                throw new RuntimeException('La cuenta id=' . self::ID_USUARIO . ' / username=' . self::USERNAME . ' no existe o no está activa: no se escribió nada.');
            }

            $this->call([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);

            $idRol = DB::table('rol')->where('nombre', 'Administrador')->orderBy('id_rol')->value('id_rol');

            if (! DB::table('usuario_rol')->where('id_usuario', self::ID_USUARIO)->where('id_rol', $idRol)->exists()) {
                DB::table('usuario_rol')->insert(['id_usuario' => self::ID_USUARIO, 'id_rol' => $idRol]);
            }

            if (! DB::table('administrador')->where('id_administrador', self::ID_USUARIO)->exists()) {
                $codigoLibre = ! DB::table('administrador')->where('codigo', self::CODIGO_ADMINISTRADOR)->exists();

                DB::table('administrador')->insert([
                    'id_administrador' => self::ID_USUARIO,
                    'codigo' => $codigoLibre ? self::CODIGO_ADMINISTRADOR : null,
                ]);
            }
        });

        // Una asignación previa desactivada se respeta: no se reactiva desde aquí.
        $estadoAsignacion = DB::table('usuario_rol')
            ->join('rol', 'rol.id_rol', '=', 'usuario_rol.id_rol')
            ->where('usuario_rol.id_usuario', self::ID_USUARIO)
            ->where('rol.nombre', 'Administrador')
            ->orderBy('rol.id_rol')
            ->value('usuario_rol.estado');

        if ($estadoAsignacion !== 'Activo') {
            $this->command?->warn('La asignación del rol Administrador existe pero está «' . $estadoAsignacion . '»: no se modificó y la cuenta seguirá sin esos permisos.');

            return;
        }

        $this->command?->info('Rol Administrador asignado a la cuenta existente «' . self::USERNAME . '» (sin cambiar sus credenciales).');
    }
}
