<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\DependenciasUsuario;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Único valor de users.estado que permite iniciar sesión y tener permisos
     * (es también el valor por defecto de la columna en la base de datos).
     */
    public const ESTADO_ACTIVO = 'Activo';

    /** Cuenta deshabilitada: no puede iniciar sesión ni tiene permisos. */
    public const ESTADO_INACTIVO = 'Inactivo';

    public const ESTADOS = [self::ESTADO_ACTIVO, self::ESTADO_INACTIVO];

    /** Perfiles de uso (determinan el menú y el acceso a las áreas del sistema). */
    public const PERFIL_PERSONAL = 'personal';

    public const PERFIL_MEDICO = 'medico';

    public const PERFIL_PACIENTE = 'paciente';

    public const PERFIL_SIN_PERFIL = 'sin_perfil';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ci', 
        'nombre', 
        'apellido', 
        'telefono', 
        'email', 
        'direccion',
        'password', 
        'sexo', 
        'fecha_nacimiento', 
        'username', 
        'estado'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Códigos de permiso del usuario, obtenidos de sus roles activos
     * (usuario_rol → rol_permiso → permiso). Se calculan una vez por petición.
     *
     * @var list<string>|null
     */
    protected ?array $codigosPermiso = null;

    public function tienePermiso(string $codigo): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVO) {
            return false;
        }

        $this->codigosPermiso ??= DB::table('usuario_rol')
            ->join('rol_permiso', 'rol_permiso.id_rol', '=', 'usuario_rol.id_rol')
            ->join('permiso', 'permiso.id_permiso', '=', 'rol_permiso.id_permiso')
            ->where('usuario_rol.id_usuario', $this->id)
            ->where('usuario_rol.estado', 'Activo')
            ->distinct()
            ->pluck('permiso.codigo')
            ->all();

        return in_array($codigo, $this->codigosPermiso, true);
    }

    protected ?string $perfilCalculado = null;

    /**
     * Perfil de uso según las tablas reales, en orden de prioridad:
     *  1. personal: cuenta administrativa principal, fila en `administrador` o `recepcionista`,
     *     o un rol activo en `usuario_rol` distinto de «Paciente» y «Médico» y con algún permiso;
     *  2. medico: fila en `medico` (si además es paciente, prevalece el perfil de médico);
     *  3. paciente: fila en `paciente`;
     *  4. sin_perfil: ninguna de las anteriores.
     * Se calcula una vez por petición.
     */
    public function perfil(): string
    {
        if ($this->perfilCalculado !== null) {
            return $this->perfilCalculado;
        }

        $id = (int) $this->id;

        $esPersonal = (new DependenciasUsuario($id))->esCuentaPrincipal()
            || DB::table('administrador')->where('id_administrador', $id)->exists()
            || DB::table('recepcionista')->where('id_recepcionista', $id)->exists()
            || DB::table('usuario_rol')
                ->join('rol', 'rol.id_rol', '=', 'usuario_rol.id_rol')
                ->where('usuario_rol.id_usuario', $id)
                ->where('usuario_rol.estado', self::ESTADO_ACTIVO)
                ->whereNotIn('rol.nombre', [DependenciasUsuario::ROL_PACIENTE, 'Médico'])
                // Un rol sin permisos (como Médico o Paciente) solo identifica un perfil: no
                // convierte la cuenta en personal aunque su nombre esté escrito de otra forma.
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('rol_permiso')->whereColumn('rol_permiso.id_rol', 'rol.id_rol'))
                ->exists();

        return $this->perfilCalculado = match (true) {
            $esPersonal => self::PERFIL_PERSONAL,
            DB::table('medico')->where('id_medico', $id)->exists() => self::PERFIL_MEDICO,
            DB::table('paciente')->where('id_paciente', $id)->exists() => self::PERFIL_PACIENTE,
            default => self::PERFIL_SIN_PERFIL,
        };
    }

    /**
     * Personal de recepción (fila en `recepcionista` o rol activo «Recepcionista») que no es
     * administrador. Solo decide qué menú se muestra; el acceso lo siguen controlando el perfil
     * y los permisos de cada ruta.
     */
    public function esRecepcionista(): bool
    {
        if ($this->perfil() !== self::PERFIL_PERSONAL) {
            return false;
        }

        return ! $this->esAdministrador()
            && (DB::table('recepcionista')->where('id_recepcionista', (int) $this->id)->exists() || $this->tieneRolActivo('Recepcionista'));
    }

    /**
     * Administrador de la clínica: cuenta principal, fila en `administrador` o rol activo
     * «Administrador», siempre con la cuenta activa y perfil de personal.
     */
    public function esAdministrador(): bool
    {
        if ($this->estado !== self::ESTADO_ACTIVO || $this->perfil() !== self::PERFIL_PERSONAL) {
            return false;
        }

        $id = (int) $this->id;

        return (new DependenciasUsuario($id))->esCuentaPrincipal()
            || DB::table('administrador')->where('id_administrador', $id)->exists()
            || $this->tieneRolActivo('Administrador');
    }

    private function tieneRolActivo(string $rol): bool
    {
        return DB::table('usuario_rol')
            ->join('rol', 'rol.id_rol', '=', 'usuario_rol.id_rol')
            ->where('usuario_rol.id_usuario', (int) $this->id)
            ->where('usuario_rol.estado', self::ESTADO_ACTIVO)
            ->where('rol.nombre', $rol)
            ->exists();
    }
}
