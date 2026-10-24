<?php

namespace Tests\Feature\Concerns;

use App\Models\Especialidad;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermisosCitasSeeder;
use Database\Seeders\PermisosEspecialidadesSeeder;
use Database\Seeders\PermisosMedicosSeeder;
use Database\Seeders\PermisosPacientesSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba comunes para horarios, disponibilidad, citas y agenda (SQLite en memoria).
 */
trait EscenarioClinica
{
    protected function prepararEscenario(): void
    {
        // Seguridad: estas pruebas solo deben ejecutarse sobre SQLite en memoria (phpunit.xml).
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->seed([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);
    }

    /** Lunes de la semana próxima: siempre en el futuro. */
    protected function proximoLunes(): CarbonImmutable
    {
        return CarbonImmutable::today()->addWeek()->startOfWeek();
    }

    protected function cuenta(string $username, ?string $rol = null, string $estado = 'Activo'): User
    {
        $usuario = User::create([
            'nombre' => ucfirst($username), 'apellido' => 'Prueba', 'email' => "{$username}@ejemplo.test",
            'username' => $username, 'password' => 'password', 'estado' => $estado,
        ]);

        if ($rol) {
            DB::table('usuario_rol')->insert(['id_usuario' => $usuario->id, 'id_rol' => DB::table('rol')->where('nombre', $rol)->value('id_rol')]);
        }

        return $usuario->fresh();
    }

    protected function especialidadActiva(string $nombre = 'Cardiología', string $estado = 'Activo'): Especialidad
    {
        return Especialidad::create(['nombre' => $nombre, 'estado' => $estado]);
    }

    /**
     * Médico con una especialidad y, opcionalmente, un horario (lunes por defecto).
     */
    protected function medicoCon(string $username, ?Especialidad $especialidad, ?array $horario = [1, '08:00', '10:00', 30], string $estado = 'Activo'): User
    {
        $usuario = $this->cuenta($username, null, $estado);
        DB::table('medico')->insert(['id_medico' => $usuario->id, 'matricula' => 'MAT-' . $usuario->id]);

        if ($especialidad) {
            DB::table('especialidad_medico')->insert(['id_medico' => $usuario->id, 'id_especialidad' => $especialidad->id_especialidad]);
        }

        if ($horario) {
            [$dia, $inicio, $fin, $duracion] = $horario;
            DB::table('horario_atencion')->insert([
                'id_medico' => $usuario->id, 'dia_semana' => $dia, 'hora_inicio' => $inicio . ':00', 'hora_final' => $fin . ':00', 'duracion_cita' => $duracion,
            ]);
        }

        return $usuario;
    }

    protected function pacienteDe(string $username, bool $bloqueado = false, string $estado = 'Activo'): User
    {
        $usuario = $this->cuenta($username, null, $estado);
        DB::table('paciente')->insert(['id_paciente' => $usuario->id, 'nro_ficha' => 'HC-' . $usuario->id, 'inasistencia' => 0, 'bloqueado' => $bloqueado]);

        return $usuario;
    }

    protected function insertarCita(User $paciente, User $medico, Especialidad $especialidad, string $fecha, string $inicio, string $fin, string $estado = 'Programada'): int
    {
        static $n = 0;

        return (int) DB::table('citas')->insertGetId([
            'codigo' => 'T-' . (++$n) . '-' . $paciente->id, 'id_paciente' => $paciente->id, 'id_medico' => $medico->id,
            'id_especialidad' => $especialidad->id_especialidad, 'fecha_cita' => $fecha, 'hora_inicio' => $inicio . ':00', 'hora_fin' => $fin . ':00',
            'estado' => $estado, 'fecha_creacion' => now(),
        ], 'id_cita');
    }

    protected function sinJavascript(string $html): void
    {
        foreach (['<script', 'x-data', 'x-show', 'onclick', '@click', 'build/assets'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $html);
        }
    }
}
