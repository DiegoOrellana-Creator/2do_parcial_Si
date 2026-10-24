<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Permisos del módulo de pacientes y su asignación a roles.
 *
 * Es idempotente: puede ejecutarse varias veces sin duplicar filas.
 * No asigna roles a ningún usuario; eso se hace aparte en `usuario_rol`.
 *
 *   php artisan db:seed --class=PermisosPacientesSeeder
 */
class PermisosPacientesSeeder extends Seeder
{
    public function run(): void
    {
        $permisos = [
            'pacientes.ver' => 'Ver pacientes',
            'pacientes.crear' => 'Registrar pacientes',
            'pacientes.editar' => 'Editar pacientes',
            'pacientes.eliminar' => 'Eliminar pacientes',
        ];

        $roles = [
            'Administrador' => ['pacientes.ver', 'pacientes.crear', 'pacientes.editar', 'pacientes.eliminar'],
            'Recepcionista' => ['pacientes.ver', 'pacientes.crear', 'pacientes.editar'],
        ];

        DB::transaction(function () use ($permisos, $roles) {
            foreach ($permisos as $codigo => $nombre) {
                // Solo se insertan los que faltan: un permiso ya existente conserva su nombre.
                if (! DB::table('permiso')->where('codigo', $codigo)->exists()) {
                    DB::table('permiso')->insert(['codigo' => $codigo, 'nombre' => $nombre]);
                }
            }

            foreach ($roles as $nombreRol => $codigos) {
                if (! DB::table('rol')->where('nombre', $nombreRol)->exists()) {
                    DB::table('rol')->insert(['nombre' => $nombreRol]);
                }

                $idRol = DB::table('rol')->where('nombre', $nombreRol)->orderBy('id_rol')->value('id_rol');

                foreach ($codigos as $codigo) {
                    $idPermiso = DB::table('permiso')->where('codigo', $codigo)->value('id_permiso');

                    if (! DB::table('rol_permiso')->where('id_rol', $idRol)->where('id_permiso', $idPermiso)->exists()) {
                        DB::table('rol_permiso')->insert(['id_rol' => $idRol, 'id_permiso' => $idPermiso]);
                    }
                }
            }
        });
    }
}
