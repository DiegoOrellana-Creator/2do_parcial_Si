<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Registros que dependen de un usuario y que se verían afectados al borrarlo.
 *
 * Claves foráneas reales en PostgreSQL (clinica_db):
 *  - ON DELETE CASCADE desde users: paciente, medico, recepcionista, administrador,
 *    usuario_rol, notificacion, preferencia_cuenta.
 *  - ON DELETE CASCADE desde paciente: citas (y con ellas pago e historial_estado_cita),
 *    informacion_medica, contacto_emergencia, bloqueo_paciente.
 *  - ON DELETE CASCADE desde medico: citas, horario_atencion, especialidad_medico, bloqueo_agenda.
 *  - ON DELETE SET NULL desde users: bitacora.id_usuario, citas.cancelado_por,
 *    historial_estado_cita.registrado_por (se perdería quién hizo cada acción).
 *
 * Todas las consultas son de lectura; deben ejecutarse dentro de la misma
 * transacción que el borrado y después de bloquear la fila afectada.
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

    public function esPaciente(): bool
    {
        return DB::table('paciente')->where('id_paciente', $this->idUsuario)->exists();
    }

    public function citasComoPaciente(): int
    {
        return DB::table('citas')->where('id_paciente', $this->idUsuario)->count();
    }

    public function bloqueosComoPaciente(): int
    {
        return DB::table('bloqueo_paciente')->where('id_paciente', $this->idUsuario)->count();
    }

    public function citasComoMedico(): int
    {
        return DB::table('citas')->where('id_medico', $this->idUsuario)->count();
    }

    /**
     * Funciones de personal de la clínica: tablas de subtipo y roles en usuario_rol.
     *
     * @return list<string>
     */
    public function rolesDePersonal(): array
    {
        $roles = [];

        if (DB::table('medico')->where('id_medico', $this->idUsuario)->exists()) {
            $roles[] = 'médico';
        }

        if (DB::table('recepcionista')->where('id_recepcionista', $this->idUsuario)->exists()) {
            $roles[] = 'recepcionista';
        }

        if (DB::table('administrador')->where('id_administrador', $this->idUsuario)->exists()) {
            $roles[] = 'administrador';
        }

        $asignados = DB::table('usuario_rol')
            ->join('rol', 'rol.id_rol', '=', 'usuario_rol.id_rol')
            ->where('usuario_rol.id_usuario', $this->idUsuario)
            ->where('rol.nombre', '<>', self::ROL_PACIENTE)
            ->orderBy('rol.nombre')
            ->pluck('rol.nombre')
            ->map(fn ($nombre) => 'rol «' . $nombre . '»')
            ->all();

        return array_values(array_unique([...$roles, ...$asignados]));
    }

    /**
     * Acciones registradas a nombre del usuario que quedarían sin autor (SET NULL).
     * Los eventos de su propia sesión y cuenta (inicio y cierre de sesión, cambio de contraseña…)
     * no cuentan: los tiene cualquier cuenta que haya entrado al sistema.
     */
    public function registrosDeAuditoria(): int
    {
        return DB::table('bitacora')->where('id_usuario', $this->idUsuario)
            ->where(fn ($q) => $q->whereNull('modulo')->orWhereNotIn('modulo', [Auditoria::SESION, Auditoria::CUENTA]))->count()
            + DB::table('citas')->where('cancelado_por', $this->idUsuario)->count()
            + DB::table('historial_estado_cita')->where('registrado_por', $this->idUsuario)->count();
    }

    public function notificaciones(): int
    {
        return DB::table('notificacion')->where('id_usuario', $this->idUsuario)->count();
    }

    public function tienePreferencias(): bool
    {
        return DB::table('preferencia_cuenta')->where('id_usuario', $this->idUsuario)->exists();
    }

    /**
     * Motivos que impiden eliminar la ficha de paciente (su historia clínica y administrativa).
     *
     * @return list<string>
     */
    public function impedimentosParaEliminarPaciente(): array
    {
        $motivos = [];

        if ($citas = $this->citasComoPaciente()) {
            $motivos[] = "tiene {$citas} " . ($citas === 1 ? 'cita registrada' : 'citas registradas');
        }

        if ($bloqueos = $this->bloqueosComoPaciente()) {
            $motivos[] = "tiene {$bloqueos} " . ($bloqueos === 1 ? 'bloqueo registrado' : 'bloqueos registrados');
        }

        return $motivos;
    }

    /**
     * Motivos para conservar la cuenta de acceso al quitar la ficha de paciente.
     *
     * @return list<string>
     */
    public function motivosParaConservarCuenta(): array
    {
        $motivos = [];

        if ($this->esCuentaPrincipal()) {
            $motivos[] = 'es la cuenta administrativa principal del sistema';
        }

        if ($roles = $this->rolesDePersonal()) {
            $motivos[] = 'también es ' . implode(', ', $roles);
        }

        if ($auditoria = $this->registrosDeAuditoria()) {
            $motivos[] = "figura como autor de {$auditoria} " . ($auditoria === 1 ? 'registro de auditoría' : 'registros de auditoría');
        }

        return $motivos;
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

    /**
     * Motivos que impiden eliminar la ficha de médico: se borrarían en cascada sus citas
     * (con pagos e historial), sus horarios de atención y sus bloqueos de agenda.
     *
     * @return list<string>
     */
    public function impedimentosParaEliminarMedico(): array
    {
        $motivos = [];

        if ($citas = $this->citasComoMedico()) {
            $motivos[] = "tiene {$citas} " . ($citas === 1 ? 'cita registrada' : 'citas registradas');
        }

        if ($horarios = $this->horariosComoMedico()) {
            $motivos[] = "tiene {$horarios} " . ($horarios === 1 ? 'horario de atención' : 'horarios de atención');
        }

        if ($bloqueos = $this->bloqueosAgendaComoMedico()) {
            $motivos[] = "tiene {$bloqueos} " . ($bloqueos === 1 ? 'bloqueo de agenda' : 'bloqueos de agenda');
        }

        return $motivos;
    }

    /**
     * Motivos para conservar la cuenta de acceso al quitar la ficha de médico
     * (no cuenta el propio rol de médico).
     *
     * @return list<string>
     */
    public function motivosParaConservarCuentaDeMedico(): array
    {
        $motivos = [];

        if ($this->esCuentaPrincipal()) {
            $motivos[] = 'es la cuenta administrativa principal del sistema';
        }

        $otrosRoles = array_values(array_filter($this->rolesDePersonal(), fn ($rol) => $rol !== 'médico' && $rol !== 'rol «Médico»'));
        if ($otrosRoles) {
            $motivos[] = 'también es ' . implode(', ', $otrosRoles);
        }

        if ($this->esPaciente()) {
            $motivos[] = 'también es paciente de la clínica';
        }

        if ($auditoria = $this->registrosDeAuditoria()) {
            $motivos[] = "figura como autor de {$auditoria} " . ($auditoria === 1 ? 'registro de auditoría' : 'registros de auditoría');
        }

        return $motivos;
    }

    /**
     * Motivos que impiden que el propio usuario elimine su cuenta desde el perfil.
     *
     * @return list<string>
     */
    public function impedimentosParaEliminarCuenta(): array
    {
        $motivos = [];

        if ($this->esPaciente()) {
            $motivos[] = 'tiene una ficha de paciente en la clínica';
        }

        if ($citas = $this->citasComoMedico()) {
            $motivos[] = "tiene {$citas} " . ($citas === 1 ? 'cita asignada como médico' : 'citas asignadas como médico');
        }

        return [...$motivos, ...$this->motivosParaConservarCuenta()];
    }
}
