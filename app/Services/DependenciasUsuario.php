<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Consultas sobre lo que depende de una cuenta. Las cuentas nunca se borran: estas comprobaciones
 * deciden si se puede desactivar y deben ejecutarse después de bloquear la fila afectada.
 */
class DependenciasUsuario
{
    /**
     * Rol reservado para pacientes: no cuenta como rol de personal.
     */
    public const ROL_PACIENTE = 'Paciente';

    /**
     * Cuenta administrativa principal (users.id = 1, username «admin» en clinica_db).
     * Se protege por cualquiera de los dos campos, tenga o no roles asignados.
     */
    public const CUENTA_PRINCIPAL_ID = 1;

    public const CUENTA_PRINCIPAL_USERNAME = 'admin';

    public function __construct(private readonly int $idUsuario)
    {
    }

    public function esCuentaPrincipal(): bool
    {
        return $this->idUsuario === self::CUENTA_PRINCIPAL_ID
            || DB::table('users')->where('id', $this->idUsuario)->where('username', self::CUENTA_PRINCIPAL_USERNAME)->exists();
    }

    /**
     * Citas futuras aún vigentes (Programada o Confirmada) del paciente.
     */
    public function citasPendientesComoPaciente(): int
    {
        return DB::table('citas')
            ->where('id_paciente', $this->idUsuario)
            ->whereIn('estado', ['Programada', 'Confirmada'])
            ->where('fecha_cita', '>=', now()->toDateString())
            ->count();
    }

    public function citasComoMedico(): int
    {
        return DB::table('citas')->where('id_medico', $this->idUsuario)->count();
    }

    public function horariosComoMedico(): int
    {
        return DB::table('horario_atencion')->where('id_medico', $this->idUsuario)->count();
    }

    public function bloqueosAgendaComoMedico(): int
    {
        return DB::table('bloqueo_agenda')->where('id_medico', $this->idUsuario)->count();
    }

    /**
     * Citas futuras aún vigentes (Programada o Confirmada) del médico, opcionalmente de una especialidad.
     */
    public function citasPendientesComoMedico(?int $idEspecialidad = null): int
    {
        return DB::table('citas')
            ->where('id_medico', $this->idUsuario)
            ->whereIn('estado', ['Programada', 'Confirmada'])
            ->where('fecha_cita', '>=', now()->toDateString())
            ->when($idEspecialidad, fn ($q) => $q->where('id_especialidad', $idEspecialidad))
            ->count();
    }
}
