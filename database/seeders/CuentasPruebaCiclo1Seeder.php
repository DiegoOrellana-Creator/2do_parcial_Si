<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Cuentas de PRUEBA que necesita `database/scripts/poblar_ciclo1.sql`.
 *
 * Solo crea filas en `users`, porque la contraseña debe guardarse con el hash de Laravel y no
 * se escriben hashes a mano en el SQL. Los perfiles (médico, paciente, recepcionista), roles,
 * especialidades, horarios y citas los inserta el script SQL, que se ejecuta DESPUÉS.
 *
 * - No modifica, borra ni reemplaza cuentas existentes: si el username, el correo o el CI ya
 *   están en uso, esa cuenta se omite y se informa.
 * - Idempotente: puede ejecutarse varias veces.
 * - La contraseña se toma de la variable de entorno SEED_TEST_PASSWORD (no de `.env`):
 *
 *   PowerShell:  $env:SEED_TEST_PASSWORD = '...'; php artisan db:seed --class=CuentasPruebaCiclo1Seeder
 *   Bash:        SEED_TEST_PASSWORD='...' php artisan db:seed --class=CuentasPruebaCiclo1Seeder
 *
 * Datos ficticios: correos con dominio reservado .test y CI que empiezan por 99.
 */
class CuentasPruebaCiclo1Seeder extends Seeder
{
    /** [username, nombre, apellido, ci, sexo, fecha_nacimiento, teléfono, estado] */
    public const CUENTAS = [
        // Médicos (perfil en `medico`, ver sección 6 del SQL)
        ['medico02', 'Carla', 'Méndez Vargas', '9900002', 'Femenino', '1984-05-12', '70100002', 'Activo'],
        ['medico03', 'Luis', 'Herrera Rojas', '9900003', 'Masculino', '1979-09-30', '70100003', 'Activo'],
        ['medico04', 'Gabriela', 'Suárez Pinto', '9900004', 'Femenino', '1988-02-17', '70100004', 'Activo'],
        ['medico05', 'Jorge', 'Antelo Flores', '9900005', 'Masculino', '1975-11-08', '70100005', 'Inactivo'],
        // Recepción (perfil en `recepcionista`)
        ['recepcion01', 'Paola', 'Saucedo Roca', '9900010', 'Femenino', '1995-07-21', '70100010', 'Activo'],
        // Pacientes (perfil en `paciente`)
        ['paciente02', 'María', 'Rojas Vaca', '9900102', 'Femenino', '1990-03-14', '70200002', 'Activo'],
        ['paciente03', 'Mateo', 'Justiniano Peña', '9900103', 'Masculino', '2018-06-21', '70200003', 'Activo'],
        ['paciente04', 'Roberto', 'Chávez Montaño', '9900104', 'Masculino', '1962-01-30', '70200004', 'Activo'],
        ['paciente05', 'Lucía', 'Salvatierra Ortiz', '9900105', 'Femenino', '1971-10-05', '70200005', 'Activo'],
        ['paciente06', 'Diego', 'Rivero Parada', '9900106', 'Masculino', '1999-12-01', '70200006', 'Activo'],
        ['paciente07', 'Valeria', 'Gutiérrez Áñez', '9900107', 'Femenino', '1993-04-27', '70200007', 'Activo'],
        ['paciente08', 'Héctor', 'Mendoza Durán', '9900108', 'Masculino', '1985-08-19', '70200008', 'Activo'],
        ['paciente09', 'Sofía', 'Cuéllar Terrazas', '9900109', 'Femenino', '2001-02-11', '70200009', 'Activo'],
    ];

    public function run(): void
    {
        $password = (string) getenv('SEED_TEST_PASSWORD');

        if (mb_strlen($password) < 8) {
            throw new RuntimeException('Define la variable de entorno SEED_TEST_PASSWORD (mínimo 8 caracteres) para las cuentas de prueba: no se escribió nada.');
        }

        $hash = Hash::make($password);
        $creadas = [];
        $omitidas = [];

        DB::transaction(function () use ($hash, &$creadas, &$omitidas) {
            foreach (self::CUENTAS as [$username, $nombre, $apellido, $ci, $sexo, $nacimiento, $telefono, $estado]) {
                $email = $username . '@clinicasantacruz.test';

                $enUso = DB::table('users')
                    ->where('username', $username)->orWhere('email', $email)->orWhere('ci', $ci)
                    ->exists();

                if ($enUso) {
                    $omitidas[] = $username;

                    continue;
                }

                DB::table('users')->insert([
                    'ci' => $ci,
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'telefono' => $telefono,
                    'email' => $email,
                    'estado' => $estado,
                    'direccion' => 'Santa Cruz de la Sierra (dirección de prueba)',
                    'password' => $hash,
                    'sexo' => $sexo,
                    'fecha_nacimiento' => $nacimiento,
                    'username' => $username,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $creadas[] = $username;
            }
        });

        $this->command?->info('Cuentas creadas: ' . (implode(', ', $creadas) ?: 'ninguna'));
        $this->command?->info('Omitidas porque el usuario, correo o CI ya existía (no se modificaron): ' . (implode(', ', $omitidas) ?: 'ninguna'));
    }
}
