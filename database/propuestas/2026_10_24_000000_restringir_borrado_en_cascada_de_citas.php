<?php

/*
|--------------------------------------------------------------------------
| PROPUESTA — NO EJECUTADA
|--------------------------------------------------------------------------
| Esta migración está fuera de database/migrations a propósito, para que
| `php artisan migrate` no la ejecute. Para aplicarla, cuando esté autorizada:
|   1. Copiar este archivo a database/migrations/.
|   2. Respaldar la base:  pg_dump -U postgres -F c -f clinica_db_antes_restrict.dump clinica_db
|   3. php artisan migrate
|
| Cambia las claves foráneas que hoy borran citas en cascada por RESTRICT:
| la base de datos rechazará (error 23503) el borrado de un paciente, médico,
| especialidad o cita que tenga registros dependientes, aunque el intento venga
| de otro módulo, del perfil o de SQL manual.
|
| Los nombres de las restricciones son los reales de clinica_db (verificados
| en pg_constraint). Solo para PostgreSQL.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * [tabla, restricción, columna, tabla referenciada, columna referenciada]
     */
    private const CLAVES = [
        // Imprescindible: un paciente con citas no se puede borrar (ni directa ni indirectamente vía users).
        ['citas', 'citas_id_paciente_foreign', 'id_paciente', 'paciente', 'id_paciente'],
        // Recomendado para «Gestionar médicos»: borrar un médico ya no borra sus citas.
        ['citas', 'citas_id_medico_foreign', 'id_medico', 'medico', 'id_medico'],
        // Recomendado para «Gestionar especialidades»: borrar una especialidad ya no borra sus citas.
        ['citas', 'citas_id_especialidad_foreign', 'id_especialidad', 'especialidad', 'id_especialidad'],
        // Recomendado: un pago nunca se pierde por borrar su cita.
        ['pago', 'pago_id_cita_foreign', 'id_cita', 'citas', 'id_cita'],
    ];

    public function up(): void
    {
        $this->cambiar('RESTRICT');
    }

    public function down(): void
    {
        $this->cambiar('CASCADE');
    }

    private function cambiar(string $accion): void
    {
        DB::transaction(function () use ($accion) {
            foreach (self::CLAVES as [$tabla, $restriccion, $columna, $referencia, $columnaReferencia]) {
                DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT {$restriccion}");
                DB::statement(
                    "ALTER TABLE {$tabla} ADD CONSTRAINT {$restriccion} FOREIGN KEY ({$columna}) "
                    . "REFERENCES {$referencia} ({$columnaReferencia}) ON DELETE {$accion}"
                );
            }
        });
    }
};
