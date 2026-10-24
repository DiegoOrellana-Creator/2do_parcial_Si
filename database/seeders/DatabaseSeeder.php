<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Antes creaba un «Test User» con la columna `name`, que no existe en `users`
     * y hacía fallar el comando. Ahora ejecuta la población de prueba, que es
     * idempotente y no modifica registros existentes.
     *
     * La asignación del rol a la cuenta administrativa existente es aparte y opcional:
     *   php artisan db:seed --class=AdministradorExistenteSeeder
     */
    public function run(): void
    {
        $this->call(PoblacionPruebaSeeder::class);
    }
}
