<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Permisos de Horarios de atención, Disponibilidad, Gestión de citas y Agenda (Ciclo 1),
 * y su asignación a roles.
 *
 * Es idempotente: puede ejecutarse varias veces sin duplicar filas.
 * No asigna roles a ningún usuario; eso se hace aparte en `usuario_rol`.
 *
 *   php artisan db:seed --class=PermisosCitasSeeder
 */
class PermisosCitasSeeder extends Seeder
{
    public function run(): void
    {
        $permisos = [
            'horarios.ver' => 'Ver horarios de atención',
            'horarios.crear' => 'Registrar horarios de atención',
            'horarios.editar' => 'Editar horarios de atención',
            'horarios.eliminar' => 'Eliminar horarios de atención',
            'disponibilidad.ver' => 'Consultar disponibilidad de citas',
            'citas.ver' => 'Ver citas',
            'citas.crear' => 'Registrar citas',
            'citas.cancelar' => 'Cancelar citas',
            'agenda.ver' => 'Consultar la agenda médica',
        ];

        // La recepción consulta horarios y agenda, y registra y cancela citas; los horarios los
        // gestiona administración.
        $roles = [
            'Administrador' => array_keys($permisos),
            'Recepcionista' => ['horarios.ver', 'disponibilidad.ver', 'citas.ver', 'citas.crear', 'citas.cancelar', 'agenda.ver'],
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
