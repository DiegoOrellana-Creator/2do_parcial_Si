<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Prueba\DatosFicticios as D;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Población de PRUEBA para la Clínica Santa Cruz.
 *
 *   php artisan db:seed --class=PoblacionPruebaSeeder
 *
 * - Solo inserta en tablas y columnas existentes; no modifica el esquema.
 * - No modifica, borra ni reemplaza registros existentes (incluido el administrador actual).
 * - Idempotente: cada registro se identifica por su clave única real (username, codigo de
 *   cita, matrícula...) o por una combinación natural, y no se duplica al volver a ejecutar.
 * - Determinista: usa un generador propio con semilla fija y una fecha de referencia fija.
 * - Todo ocurre en una transacción: si algo falla, no queda nada a medias.
 *
 * Contraseña de las cuentas de prueba: variable de entorno SEED_TEST_PASSWORD. Si no está
 * definida, cada ejecución usa una contraseña aleatoria que no se muestra (las cuentas
 * podrán recuperarla con «¿Olvidaste tu contraseña?»). Las cuentas ya existentes no cambian.
 */
class PoblacionPruebaSeeder extends Seeder
{
    /** «Hoy» para los datos: separa citas pasadas de futuras. */
    private const FECHA_REFERENCIA = '2026-10-24';

    private const SEMILLA = 20261024;

    private const TOTAL_MEDICOS = 20;

    private const TOTAL_PACIENTES = 100;

    private const TOTAL_RECEPCIONISTAS = 5;

    private const OBJETIVO_CITAS = 220;

    private const DIAS_ATRAS = 28;

    private const DIAS_ADELANTE = 28;

    /** Estados de cita usados por el proyecto (components/admin/status-badge.blade.php). */
    private const PROGRAMADA = 'Programada';

    private const CONFIRMADA = 'Confirmada';

    private const ATENDIDA = 'Atendida';

    private const CANCELADA = 'Cancelada';

    private const NO_ASISTIO = 'No asistió';

    /** 'Pendiente' es el valor por defecto de la base; 'Pagado' y 'Enviado' son DECISIONES A CONFIRMAR. */
    private const PAGO_PENDIENTE = 'Pendiente';

    private const PAGO_PAGADO = 'Pagado';

    private const ENVIO_PENDIENTE = 'Pendiente';

    private const ENVIO_ENVIADO = 'Enviado';

    private const METODOS_PAGO = ['Efectivo', 'Tarjeta', 'Transferencia QR'];

    /** Roles nuevos (Administrador y Recepcionista los crea PermisosPacientesSeeder). */
    private const ROLES_NUEVOS = [
        'Médico' => 'Personal médico de la clínica.',
        'Paciente' => 'Pacientes registrados en la clínica.',
    ];

    /** Pacientes de prueba con 3 inasistencias: los 4 primeros siguen bloqueados, los 2 últimos ya fueron desbloqueados. */
    private const PACIENTES_BLOQUEADOS_ACTIVOS = [90, 91, 92, 93];

    private const PACIENTES_DESBLOQUEADOS = [94, 95];

    private int $estadoAzar;

    private string $hashPassword;

    private CarbonImmutable $hoy;

    /** @var array<string, int> filas insertadas por tabla */
    private array $insertados = [];

    /** @var array<string, int> */
    private array $omitidos = [];

    public function run(): void
    {
        $this->estadoAzar = self::SEMILLA;
        $this->hoy = CarbonImmutable::parse(self::FECHA_REFERENCIA)->startOfDay();
        $this->hashPassword = Hash::make((string) (env('SEED_TEST_PASSWORD') ?: Str::password(40)));

        DB::transaction(function () {
            $this->call([PermisosPacientesSeeder::class, PermisosEspecialidadesSeeder::class, PermisosMedicosSeeder::class, PermisosCitasSeeder::class]);

            $roles = $this->roles();
            $especialidades = $this->especialidades();
            $recepcionistas = $this->recepcionistas($roles);
            $medicos = $this->medicos($roles, $especialidades);
            $bloqueosAgenda = $this->bloqueosAgenda($medicos);
            $plan = $this->planificarCitas($medicos, $bloqueosAgenda);
            $pacientes = $this->pacientes($roles, $plan, $recepcionistas);
            $this->bloqueosPacientes($pacientes, $plan);
            $this->citas($plan, $pacientes, $medicos, $especialidades, $recepcionistas);
        });

        $this->informar();
    }

    // ---------------------------------------------------------------------
    // Seguridad
    // ---------------------------------------------------------------------

    /** @return array<string, int> nombre del rol => id_rol */
    private function roles(): array
    {
        foreach (self::ROLES_NUEVOS as $nombre => $descripcion) {
            if (! DB::table('rol')->where('nombre', $nombre)->exists()) {
                DB::table('rol')->insert(['nombre' => $nombre, 'descripcion' => $descripcion]);
                $this->contar('rol');
            }
        }

        $roles = [];
        foreach (['Administrador', 'Recepcionista', ...array_keys(self::ROLES_NUEVOS)] as $nombre) {
            $roles[$nombre] = (int) DB::table('rol')->where('nombre', $nombre)->orderBy('id_rol')->value('id_rol');
        }

        return $roles;
    }

    private function asignarRol(int $idUsuario, int $idRol): void
    {
        if (! DB::table('usuario_rol')->where('id_usuario', $idUsuario)->where('id_rol', $idRol)->exists()) {
            DB::table('usuario_rol')->insert([
                'id_usuario' => $idUsuario,
                'id_rol' => $idRol,
                'fecha_asignacion' => $this->hoy->subDays(40)->setTime(9, 0)->toDateTimeString(),
            ]);
            $this->contar('usuario_rol');
        }
    }

    private function preferencias(int $idUsuario, bool $whatsapp, bool $correo): void
    {
        if (! DB::table('preferencia_cuenta')->where('id_usuario', $idUsuario)->exists()) {
            DB::table('preferencia_cuenta')->insert([
                'id_usuario' => $idUsuario,
                'acepta_whatsapp' => $whatsapp,
                'acepta_correo' => $correo,
                'privacidad' => 'Acepta el uso de sus datos para la gestión de citas (dato de prueba).',
            ]);
            $this->contar('preferencia_cuenta');
        }
    }

    private function bitacora(?int $idUsuario, string $accion, string $modulo, int $idRegistro, string $detalle, CarbonImmutable $fecha, int $equipo): void
    {
        DB::table('bitacora')->insert([
            'id_usuario' => $idUsuario,
            'fecha_hora' => $fecha->toDateTimeString(),
            'accion' => $accion,
            'modulo' => $modulo,
            'id_registro' => $idRegistro,
            'detalle' => $detalle,
            'ip' => '192.168.10.' . (10 + $equipo),
        ]);
        $this->contar('bitacora');
    }

    /**
     * Crea un usuario de prueba si su username no existe. Nunca modifica uno existente.
     *
     * @param  array<string, mixed>  $persona
     * @return array{0: int, 1: bool} [id, recién creado]
     */
    private function usuario(string $username, array $persona, CarbonImmutable $creado): array
    {
        $existente = DB::table('users')->where('username', $username)->value('id');

        if ($existente) {
            return [(int) $existente, false];
        }

        $email = $username . '@ejemplo.test';

        // Si el correo o el CI ya pertenecen a otra cuenta, se aborta todo (la transacción se revierte).
        if (DB::table('users')->where('email', $email)->orWhere('ci', $persona['ci'])->exists()) {
            throw new RuntimeException("El correo {$email} o el CI {$persona['ci']} ya pertenecen a otra cuenta: no se insertó nada.");
        }

        $id = DB::table('users')->insertGetId([
            'ci' => $persona['ci'],
            'nombre' => $persona['nombre'],
            'apellido' => $persona['apellido'],
            'telefono' => $persona['telefono'],
            'email' => $email,
            'estado' => 'Activo',
            'direccion' => $persona['direccion'],
            'password' => $this->hashPassword,
            'sexo' => $persona['sexo'],
            'fecha_nacimiento' => $persona['fecha_nacimiento'],
            'username' => $username,
            'created_at' => $creado->toDateTimeString(),
            'updated_at' => $creado->toDateTimeString(),
        ], 'id');
        $this->contar('users');

        return [(int) $id, true];
    }

    // ---------------------------------------------------------------------
    // Personal y especialidades
    // ---------------------------------------------------------------------

    /** @return list<array{id: int, nombre: string, precio: int}> */
    private function especialidades(): array
    {
        $lista = [];

        foreach (D::ESPECIALIDADES as [$nombre, $descripcion, $precio]) {
            $id = DB::table('especialidad')->where('nombre', $nombre)->orderBy('id_especialidad')->value('id_especialidad');

            if (! $id) {
                $id = DB::table('especialidad')->insertGetId(
                    ['nombre' => $nombre, 'descripcion' => $descripcion, 'estado' => 'Activo'],
                    'id_especialidad'
                );
                $this->contar('especialidad');
            }

            $lista[] = ['id' => (int) $id, 'nombre' => $nombre, 'precio' => $precio];
        }

        return $lista;
    }

    /** @return list<int> ids de usuario */
    private function recepcionistas(array $roles): array
    {
        $ids = [];

        for ($i = 0; $i < self::TOTAL_RECEPCIONISTAS; $i++) {
            $persona = $this->persona(2001 + $i, $i % 2 === 0 ? 'Femenino' : 'Masculino', 22, 45);
            $codigo = 'REC-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            [$id] = $this->usuario('recepcion' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), $persona, $this->hoy->subDays(60));

            $this->subtipo('recepcionista', 'id_recepcionista', $id, ['codigo' => $codigo], 'codigo', $codigo);
            $this->asignarRol($id, $roles['Recepcionista']);
            $this->preferencias($id, true, true);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @return list<array{id: int, nombre: string, especialidades: list<int>, horarios: list<array{dia: int, inicio: string, fin: string, duracion: int}>}>
     */
    private function medicos(array $roles, array $especialidades): array
    {
        // Patrones de horario. dia_semana: 1 = lunes … 7 = domingo (ISO-8601). DECISIÓN A CONFIRMAR.
        $patrones = [
            ['dias' => [1, 3, 5], 'inicio' => '08:00', 'fin' => '12:00', 'duracion' => 30],
            ['dias' => [2, 4], 'inicio' => '14:00', 'fin' => '18:00', 'duracion' => 30],
            ['dias' => [1, 3, 5], 'inicio' => '14:00', 'fin' => '18:00', 'duracion' => 20],
            ['dias' => [2, 4], 'inicio' => '08:00', 'fin' => '12:00', 'duracion' => 20],
        ];

        $medicos = [];

        for ($i = 0; $i < self::TOTAL_MEDICOS; $i++) {
            $sexo = $i % 2 === 0 ? 'Masculino' : 'Femenino';
            $persona = $this->persona(1001 + $i, $sexo, 30, 65);
            $numero = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            [$id] = $this->usuario('medico' . substr($numero, 1), $persona, $this->hoy->subDays(60));

            $this->subtipo('medico', 'id_medico', $id, ['matricula' => 'MAT-PRB-' . $numero, 'codigo' => 'MED-' . $numero], 'matricula', 'MAT-PRB-' . $numero);
            $this->asignarRol($id, $roles['Médico']);
            $this->preferencias($id, true, true);

            // Cada médico tiene su especialidad principal; los 5 primeros, una segunda (cubre las 25).
            $indices = $i < 5 ? [$i, 20 + $i] : [$i];
            foreach ($indices as $indice) {
                $idEsp = $especialidades[$indice]['id'];
                if (! DB::table('especialidad_medico')->where('id_medico', $id)->where('id_especialidad', $idEsp)->exists()) {
                    DB::table('especialidad_medico')->insert(['id_medico' => $id, 'id_especialidad' => $idEsp]);
                    $this->contar('especialidad_medico');
                }
            }

            $patron = $patrones[$i % 4];
            $horarios = [];
            foreach ($patron['dias'] as $dia) {
                $existe = DB::table('horario_atencion')->where('id_medico', $id)->where('dia_semana', $dia)
                    ->where('hora_inicio', $patron['inicio'] . ':00')->exists();

                if (! $existe) {
                    DB::table('horario_atencion')->insert([
                        'id_medico' => $id,
                        'dia_semana' => $dia,
                        'hora_inicio' => $patron['inicio'] . ':00',
                        'hora_final' => $patron['fin'] . ':00',
                        'duracion_cita' => $patron['duracion'],
                    ]);
                    $this->contar('horario_atencion');
                }

                $horarios[] = ['dia' => $dia, 'inicio' => $patron['inicio'], 'fin' => $patron['fin'], 'duracion' => $patron['duracion']];
            }

            $medicos[] = [
                'id' => $id,
                'nombre' => ($sexo === 'Femenino' ? 'Dra. ' : 'Dr. ') . $persona['nombre'] . ' ' . explode(' ', $persona['apellido'])[0],
                'especialidades' => $indices,
                'horarios' => $horarios,
            ];
        }

        return $medicos;
    }

    /**
     * Inserta una fila de subtipo (medico, recepcionista) si no existe. Aborta si el código único lo usa otra persona.
     */
    private function subtipo(string $tabla, string $clave, int $id, array $datos, string $columnaUnica, string $valorUnico): void
    {
        if (DB::table($tabla)->where($clave, $id)->exists()) {
            return;
        }

        if (DB::table($tabla)->where($columnaUnica, $valorUnico)->exists()) {
            throw new RuntimeException("{$tabla}.{$columnaUnica} = {$valorUnico} ya pertenece a otro registro: no se insertó nada.");
        }

        DB::table($tabla)->insert([$clave => $id, ...$datos]);
        $this->contar($tabla);
    }

    /** @return list<array{medico: int, fecha: string, inicio: string, fin: string}> índice de médico y franja bloqueada */
    private function bloqueosAgenda(array $medicos): array
    {
        $bloqueos = [];

        for ($j = 0; $j < 8; $j++) {
            $m = ($j * 3) % self::TOTAL_MEDICOS;
            $horario = $medicos[$m]['horarios'][$j % count($medicos[$m]['horarios'])];
            $fecha = $this->siguienteDia($this->hoy->addDays($j * 6 - 20), $horario['dia']);
            $inicio = $horario['inicio'];
            $fin = CarbonImmutable::parse($inicio)->addHours(2)->format('H:i');
            $idMedico = $medicos[$m]['id'];

            $existe = DB::table('bloqueo_agenda')->where('id_medico', $idMedico)->where('fecha', $fecha->toDateString())
                ->where('hora_inicio', $inicio . ':00')->exists();

            if (! $existe) {
                DB::table('bloqueo_agenda')->insert([
                    'id_medico' => $idMedico,
                    'fecha' => $fecha->toDateString(),
                    'hora_inicio' => $inicio . ':00',
                    'hora_final' => $fin . ':00',
                    'motivo' => D::MOTIVOS_BLOQUEO_AGENDA[$j % count(D::MOTIVOS_BLOQUEO_AGENDA)],
                ]);
                $this->contar('bloqueo_agenda');
            }

            $bloqueos[] = ['medico' => $m, 'fecha' => $fecha->toDateString(), 'inicio' => $inicio, 'fin' => $fin];
        }

        return $bloqueos;
    }

    // ---------------------------------------------------------------------
    // Planificación de citas (en memoria, antes de insertar)
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function planificarCitas(array $medicos, array $bloqueosAgenda): array
    {
        $plan = [];
        $ocupadoMedico = [];
        $ocupadoPaciente = [];
        $inasistencias = array_fill(0, self::TOTAL_PACIENTES, 0);
        $bloqueadosActivos = array_flip(self::PACIENTES_BLOQUEADOS_ACTIVOS);

        $libre = function (int $m, int $p, string $fecha, string $inicio, string $fin) use (&$ocupadoMedico, &$ocupadoPaciente, $bloqueosAgenda): bool {
            if (isset($ocupadoMedico["{$m}|{$fecha}|{$inicio}"]) || isset($ocupadoPaciente["{$p}|{$fecha}"])) {
                return false;
            }

            foreach ($bloqueosAgenda as $b) {
                if ($b['medico'] === $m && $b['fecha'] === $fecha && $inicio < $b['fin'] && $fin > $b['inicio']) {
                    return false;
                }
            }

            return true;
        };

        $agregar = function (int $m, int $p, string $fecha, string $inicio, string $fin, string $estado) use (&$plan, &$ocupadoMedico, &$ocupadoPaciente, &$inasistencias, $medicos) {
            $ocupadoMedico["{$m}|{$fecha}|{$inicio}"] = true;
            $ocupadoPaciente["{$p}|{$fecha}"] = true;

            if ($estado === self::NO_ASISTIO) {
                $inasistencias[$p]++;
            }

            $plan[] = [
                'codigo' => 'PRB-' . str_pad((string) (count($plan) + 1), 4, '0', STR_PAD_LEFT),
                'medico' => $m,
                'paciente' => $p,
                'especialidad' => $this->elegir($medicos[$m]['especialidades']),
                'fecha' => $fecha,
                'inicio' => $inicio,
                'fin' => $fin,
                'estado' => $estado,
                'motivo' => $this->elegir(D::MOTIVOS_CITA),
                'recepcionista' => $this->azar(0, self::TOTAL_RECEPCIONISTAS - 1),
                'dias_antelacion' => $this->azar(2, 14),
                'hora_creacion' => $this->azar(8, 17),
                'motivo_cancelacion' => $this->elegir(D::MOTIVOS_CANCELACION),
                'metodo_pago' => $this->elegir(self::METODOS_PAGO),
                'leido' => $this->azar(0, 1) === 1,
            ];
        };

        // 1) Inasistencias que justifican los bloqueos de paciente: 3 por paciente, entre 28 y 18 días atrás.
        foreach ([...self::PACIENTES_BLOQUEADOS_ACTIVOS, ...self::PACIENTES_DESBLOQUEADOS] as $p) {
            $creadas = 0;
            for ($intento = 0; $creadas < 3 && $intento < 200; $intento++) {
                $m = $this->azar(0, self::TOTAL_MEDICOS - 1);
                $horario = $this->elegir($medicos[$m]['horarios']);
                $fecha = $this->siguienteDia($this->hoy->subDays($this->azar(18, 28)), $horario['dia']);
                if ($fecha->greaterThan($this->hoy->subDays(15))) {
                    continue;
                }
                [$inicio, $fin] = $this->elegir($this->franjas($horario));
                if ($libre($m, $p, $fecha->toDateString(), $inicio, $fin)) {
                    $agregar($m, $p, $fecha->toDateString(), $inicio, $fin, self::NO_ASISTIO);
                    $creadas++;
                }
            }
        }

        // 2) Resto de citas: médicos, pacientes, fechas y estados variados.
        for ($intento = 0; count($plan) < self::OBJETIVO_CITAS && $intento < 10000; $intento++) {
            $m = $this->azar(0, self::TOTAL_MEDICOS - 1);
            $p = $this->azar(0, self::TOTAL_PACIENTES - 1);
            $horario = $this->elegir($medicos[$m]['horarios']);
            $fecha = $this->siguienteDia($this->hoy->addDays($this->azar(-self::DIAS_ATRAS, self::DIAS_ADELANTE)), $horario['dia']);
            [$inicio, $fin] = $this->elegir($this->franjas($horario));
            $pasada = $fecha->lessThan($this->hoy);
            $azar = $this->azar(1, 100);

            if ($fecha->greaterThan($this->hoy->addDays(self::DIAS_ADELANTE)) || $fecha->lessThan($this->hoy->subDays(self::DIAS_ATRAS))) {
                continue;
            }

            // Un paciente bloqueado no puede tener citas futuras.
            if (! $pasada && isset($bloqueadosActivos[$p])) {
                continue;
            }

            if (! $libre($m, $p, $fecha->toDateString(), $inicio, $fin)) {
                continue;
            }

            if ($pasada) {
                $estado = $azar <= 65 ? self::ATENDIDA : ($azar <= 75 ? self::NO_ASISTIO : self::CANCELADA);
                // Los demás pacientes no acumulan las 3 inasistencias que implicarían un bloqueo.
                if ($estado === self::NO_ASISTIO && ($inasistencias[$p] >= 2 || isset($bloqueadosActivos[$p]) || in_array($p, self::PACIENTES_DESBLOQUEADOS, true))) {
                    $estado = self::ATENDIDA;
                }
            } else {
                $estado = $azar <= 50 ? self::PROGRAMADA : ($azar <= 85 ? self::CONFIRMADA : self::CANCELADA);
            }

            $agregar($m, $p, $fecha->toDateString(), $inicio, $fin, $estado);
        }

        return $plan;
    }

    /** @return list<array{0: string, 1: string}> franjas [inicio, fin] de un horario */
    private function franjas(array $horario): array
    {
        $franjas = [];
        $inicio = CarbonImmutable::parse($horario['inicio']);
        $limite = CarbonImmutable::parse($horario['fin']);

        while ($inicio->addMinutes($horario['duracion'])->lessThanOrEqualTo($limite)) {
            $franjas[] = [$inicio->format('H:i'), $inicio->addMinutes($horario['duracion'])->format('H:i')];
            $inicio = $inicio->addMinutes($horario['duracion']);
        }

        return $franjas;
    }

    // ---------------------------------------------------------------------
    // Pacientes
    // ---------------------------------------------------------------------

    /**
     * @return list<array{id: int, whatsapp: bool}>
     */
    private function pacientes(array $roles, array $plan, array $recepcionistas): array
    {
        $inasistencias = array_fill(0, self::TOTAL_PACIENTES, 0);
        foreach ($plan as $cita) {
            if ($cita['estado'] === self::NO_ASISTIO) {
                $inasistencias[$cita['paciente']]++;
            }
        }

        $pacientes = [];

        for ($p = 0; $p < self::TOTAL_PACIENTES; $p++) {
            // Todos los valores aleatorios se generan siempre, exista o no el paciente, para que
            // la secuencia sea idéntica en cada ejecución.
            $sexo = $this->azar(0, 1) === 0 ? 'Femenino' : 'Masculino';
            $persona = $this->persona(3001 + $p, $sexo, 1, 85);
            $numero = str_pad((string) ($p + 1), 3, '0', STR_PAD_LEFT);
            $whatsapp = $this->azar(1, 100) <= 70;
            $info = [
                'tipo_sangre' => $this->elegir(D::TIPOS_SANGRE),
                'alergias' => $this->elegir(D::ALERGIAS),
                'enfermedades_cronicas' => $persona['edad'] >= 40 ? $this->elegir(D::ENFERMEDADES_CRONICAS) : null,
                'medicamentos' => $this->elegir(D::MEDICAMENTOS),
                'antecedentes' => $this->elegir(D::ANTECEDENTES),
            ];
            $contactos = [];
            $totalContactos = $this->azar(1, 2);
            for ($c = 0; $c < $totalContactos; $c++) {
                $sexoContacto = $this->azar(0, 1) === 0 ? 'Femenino' : 'Masculino';
                $nombres = $sexoContacto === 'Femenino' ? D::NOMBRES_FEMENINOS : D::NOMBRES_MASCULINOS;
                $contactos[] = [
                    'nombre' => $this->elegir($nombres) . ' ' . explode(' ', $persona['apellido'])[0],
                    'parentesco' => $this->elegir(D::PARENTESCOS),
                    'telefono' => '7009' . str_pad((string) ($p * 2 + $c + 1), 4, '0', STR_PAD_LEFT),
                ];
            }
            $creado = $this->hoy->subDays(45 - intdiv($p, 4));

            [$id] = $this->usuario('paciente' . $numero, $persona, $creado);

            if (! DB::table('paciente')->where('id_paciente', $id)->exists()) {
                $ficha = 'HC-PRB-' . str_pad((string) ($p + 1), 4, '0', STR_PAD_LEFT);
                if (DB::table('paciente')->where('nro_ficha', $ficha)->exists()) {
                    throw new RuntimeException("paciente.nro_ficha = {$ficha} ya pertenece a otro paciente: no se insertó nada.");
                }

                DB::table('paciente')->insert([
                    'id_paciente' => $id,
                    'nro_ficha' => $ficha,
                    'inasistencia' => $inasistencias[$p],
                    'bloqueado' => in_array($p, self::PACIENTES_BLOQUEADOS_ACTIVOS, true),
                ]);
                $this->contar('paciente');

                DB::table('informacion_medica')->insert(['id_paciente' => $id, ...$info]);
                $this->contar('informacion_medica');

                foreach ($contactos as $contacto) {
                    DB::table('contacto_emergencia')->insert(['id_paciente' => $id, ...$contacto]);
                    $this->contar('contacto_emergencia');
                }

                $recepcion = $p % self::TOTAL_RECEPCIONISTAS;
                $this->bitacora($recepcionistas[$recepcion], 'Registrar paciente', 'Pacientes', $id,
                    "Registro del paciente de prueba {$persona['nombre']} {$persona['apellido']} ({$ficha}).", $creado->setTime(10, 0), $recepcion);
            }

            $this->asignarRol($id, $roles['Paciente']);
            $this->preferencias($id, $whatsapp, true);

            $pacientes[] = ['id' => $id, 'whatsapp' => $whatsapp];
        }

        return $pacientes;
    }

    private function bloqueosPacientes(array $pacientes, array $plan): void
    {
        foreach ([...self::PACIENTES_BLOQUEADOS_ACTIVOS, ...self::PACIENTES_DESBLOQUEADOS] as $p) {
            $fechas = collect($plan)->where('paciente', $p)->where('estado', self::NO_ASISTIO)->pluck('fecha')->sort()->values();
            $idPaciente = $pacientes[$p]['id'];

            if ($fechas->count() < 3 || DB::table('bloqueo_paciente')->where('id_paciente', $idPaciente)->exists()) {
                continue;
            }

            $bloqueo = CarbonImmutable::parse($fechas->last())->addDay()->setTime(9, 0);
            $desbloqueo = in_array($p, self::PACIENTES_DESBLOQUEADOS, true) ? $bloqueo->addDays(14)->setTime(11, 0) : null;

            DB::table('bloqueo_paciente')->insert([
                'id_paciente' => $idPaciente,
                'fecha_bloqueo' => $bloqueo->toDateTimeString(),
                'fecha_desbloqueo' => $desbloqueo?->toDateTimeString(),
                'motivo' => 'Bloqueo de prueba por 3 inasistencias consecutivas.',
                'inasistencias_acumuladas' => $fechas->count(),
            ]);
            $this->contar('bloqueo_paciente');
        }
    }

    // ---------------------------------------------------------------------
    // Citas, historial, pagos, notificaciones y bitácora
    // ---------------------------------------------------------------------

    private function citas(array $plan, array $pacientes, array $medicos, array $especialidades, array $recepcionistas): void
    {
        foreach ($plan as $cita) {
            if (DB::table('citas')->where('codigo', $cita['codigo'])->exists()) {
                continue;
            }

            $idMedico = $medicos[$cita['medico']]['id'];
            $idPaciente = $pacientes[$cita['paciente']]['id'];
            $inicio = $cita['inicio'] . ':00';
            $fin = $cita['fin'] . ':00';

            // Protección frente a datos no generados por este seeder: no se solapa con ninguna
            // cita activa del médico ni del paciente que ya exista en la base.
            $solapa = DB::table('citas')->where('fecha_cita', $cita['fecha'])->where('estado', '<>', self::CANCELADA)
                ->where(fn ($q) => $q->where('id_medico', $idMedico)->orWhere('id_paciente', $idPaciente))
                ->where('hora_inicio', '<', $fin)->where('hora_fin', '>', $inicio)->exists();

            if ($solapa) {
                $this->omitidos['citas (solapamiento con datos existentes)'] = ($this->omitidos['citas (solapamiento con datos existentes)'] ?? 0) + 1;

                continue;
            }

            $momentoCita = CarbonImmutable::parse("{$cita['fecha']} {$cita['inicio']}");
            $creacion = $momentoCita->subDays($cita['dias_antelacion'])->setTime($cita['hora_creacion'], 0);
            if ($creacion->greaterThan($this->hoy->setTime(18, 0))) {
                $creacion = $this->hoy->subDays($cita['dias_antelacion'] % 5)->setTime($cita['hora_creacion'], 0);
            }
            if ($creacion->greaterThanOrEqualTo($momentoCita)) {
                $creacion = $momentoCita->subDay()->setTime(9, 0);
            }

            $recepcion = $cita['recepcionista'];
            $idRecepcionista = $recepcionistas[$recepcion];
            $cancelada = $cita['estado'] === self::CANCELADA;
            $cancelacion = null;
            if ($cancelada) {
                $tope = $momentoCita->subHour()->min($this->hoy->setTime(18, 0));
                $cancelacion = $creacion->addSeconds(intdiv(max(3600, (int) $tope->diffInSeconds($creacion, true)), 2));
            }

            $idCita = DB::table('citas')->insertGetId([
                'codigo' => $cita['codigo'],
                'id_paciente' => $idPaciente,
                'id_medico' => $idMedico,
                'id_especialidad' => $especialidades[$cita['especialidad']]['id'],
                'fecha_cita' => $cita['fecha'],
                'hora_inicio' => $inicio,
                'hora_fin' => $fin,
                'motivo' => $cita['motivo'],
                'estado' => $cita['estado'],
                'fecha_creacion' => $creacion->toDateTimeString(),
                'motivo_cancelacion' => $cancelada ? $cita['motivo_cancelacion'] : null,
                'fecha_cancelacion' => $cancelacion?->toDateTimeString(),
                'cancelado_por' => $cancelada ? $idRecepcionista : null,
            ], 'id_cita');
            $this->contar('citas');

            $this->historial($idCita, $cita, $creacion, $cancelacion, $momentoCita, $idRecepcionista, $idMedico);
            $this->pago($idCita, $cita, $especialidades[$cita['especialidad']]['precio'], $momentoCita);
            $this->notificacion($idCita, $cita, $pacientes[$cita['paciente']], $medicos[$cita['medico']]['nombre'],
                $especialidades[$cita['especialidad']]['nombre'], $momentoCita, $cancelacion);

            $this->bitacora($idRecepcionista, 'Registrar cita', 'Citas', $idCita, "Cita {$cita['codigo']} registrada.", $creacion, $recepcion);
            if ($cancelada) {
                $this->bitacora($idRecepcionista, 'Cancelar cita', 'Citas', $idCita, "Cita {$cita['codigo']} cancelada: {$cita['motivo_cancelacion']}", $cancelacion, $recepcion);
            }
        }
    }

    private function historial(int $idCita, array $cita, CarbonImmutable $creacion, ?CarbonImmutable $cancelacion, CarbonImmutable $momentoCita, int $idRecepcionista, int $idMedico): void
    {
        $cambios = [[null, self::PROGRAMADA, $creacion, 'Cita registrada.', $idRecepcionista]];

        if (in_array($cita['estado'], [self::CONFIRMADA, self::ATENDIDA, self::NO_ASISTIO], true)) {
            $confirmacion = $momentoCita->subDay()->setTime(10, 0)->max($creacion->addHour())->min($this->hoy->setTime(18, 0));
            $cambios[] = [self::PROGRAMADA, self::CONFIRMADA, $confirmacion->max($creacion->addMinute()), 'Confirmada por el paciente.', $idRecepcionista];
        }

        $fin = CarbonImmutable::parse("{$cita['fecha']} {$cita['fin']}");
        match ($cita['estado']) {
            self::ATENDIDA => $cambios[] = [self::CONFIRMADA, self::ATENDIDA, $fin, 'Consulta realizada.', $idMedico],
            self::NO_ASISTIO => $cambios[] = [self::CONFIRMADA, self::NO_ASISTIO, $fin->addMinutes(15), 'El paciente no se presentó.', $idRecepcionista],
            self::CANCELADA => $cambios[] = [self::PROGRAMADA, self::CANCELADA, $cancelacion, $cita['motivo_cancelacion'], $idRecepcionista],
            default => null,
        };

        foreach ($cambios as [$anterior, $nuevo, $momento, $motivo, $registradoPor]) {
            DB::table('historial_estado_cita')->insert([
                'id_cita' => $idCita,
                'estado_anterior' => $anterior,
                'estado_nuevo' => $nuevo,
                'fecha_hora' => $momento->toDateTimeString(),
                'motivo' => $motivo,
                'registrado_por' => $registradoPor,
            ]);
            $this->contar('historial_estado_cita');
        }
    }

    private function pago(int $idCita, array $cita, int $precio, CarbonImmutable $momentoCita): void
    {
        // Atendida → pagada al llegar; Programada/Confirmada → pago pendiente; Cancelada / No asistió → sin pago.
        if (! in_array($cita['estado'], [self::ATENDIDA, self::PROGRAMADA, self::CONFIRMADA], true)) {
            return;
        }

        $pagada = $cita['estado'] === self::ATENDIDA;
        $metodo = $pagada ? $cita['metodo_pago'] : null;

        DB::table('pago')->insert([
            'id_cita' => $idCita,
            'monto' => $precio,
            'metodo_de_pago' => $metodo,
            'id_transaccion' => $pagada && $metodo !== 'Efectivo' ? 'TRX-' . $cita['codigo'] : null,
            'estado_pago' => $pagada ? self::PAGO_PAGADO : self::PAGO_PENDIENTE,
            'fecha_pago' => $pagada ? $momentoCita->subMinutes(15)->toDateTimeString() : null,
        ]);
        $this->contar('pago');
    }

    private function notificacion(int $idCita, array $cita, array $paciente, string $medico, string $especialidad, CarbonImmutable $momentoCita, ?CarbonImmutable $cancelacion): void
    {
        $cuando = $momentoCita->format('d/m/Y') . ' a las ' . $momentoCita->format('H:i');

        if ($cita['estado'] === self::CANCELADA) {
            $tipo = 'Cancelación';
            $mensaje = "Su cita {$cita['codigo']} de {$especialidad} con {$medico} del {$cuando} fue cancelada.";
            $programada = $cancelacion;
        } else {
            $tipo = 'Recordatorio';
            $mensaje = "Recordatorio: tiene una cita de {$especialidad} con {$medico} el {$cuando}.";
            $programada = $momentoCita->subDay()->setTime(8, 0);
        }

        $enviada = $programada->lessThanOrEqualTo($this->hoy->setTime(8, 0));

        DB::table('notificacion')->insert([
            'id_usuario' => $paciente['id'],
            'tipo' => $tipo,
            'mensaje' => $mensaje,
            'canal' => $paciente['whatsapp'] ? 'WhatsApp' : 'Correo',
            'estado_envio' => $enviada ? self::ENVIO_ENVIADO : self::ENVIO_PENDIENTE,
            'fecha_programada' => $programada->toDateTimeString(),
            'fecha_envio' => $enviada ? $programada->toDateTimeString() : null,
            'leido' => $enviada && $cita['leido'],
        ]);
        $this->contar('notificacion');
    }

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> persona ficticia */
    private function persona(int $serie, string $sexo, int $edadMin, int $edadMax): array
    {
        $nombres = $sexo === 'Femenino' ? D::NOMBRES_FEMENINOS : D::NOMBRES_MASCULINOS;
        $edad = $this->azar($edadMin, $edadMax);

        return [
            'nombre' => $this->elegir($nombres),
            'apellido' => $this->elegir(D::APELLIDOS) . ' ' . $this->elegir(D::APELLIDOS),
            'sexo' => $sexo,
            'edad' => $edad,
            'fecha_nacimiento' => $this->hoy->subYears($edad)->subDays($this->azar(0, 364))->toDateString(),
            'ci' => '99' . str_pad((string) $serie, 7, '0', STR_PAD_LEFT),
            'telefono' => '7000' . str_pad((string) $serie, 4, '0', STR_PAD_LEFT),
            'direccion' => $this->elegir(D::CALLES) . ' N.º ' . $this->azar(10, 999) . ', ' . $this->elegir(D::ZONAS) . ' (dirección de prueba)',
        ];
    }

    /** Primer día igual o posterior a $desde que cae en $diaIso (1 = lunes … 7 = domingo). */
    private function siguienteDia(CarbonImmutable $desde, int $diaIso): CarbonImmutable
    {
        return $desde->addDays(($diaIso - $desde->dayOfWeekIso + 7) % 7);
    }

    /** Generador congruencial lineal con semilla fija: mismos datos en cada ejecución. */
    private function azar(int $min, int $max): int
    {
        $this->estadoAzar = ($this->estadoAzar * 1103515245 + 12345) % 2147483648;

        return $min + intdiv($this->estadoAzar, 65536) % ($max - $min + 1);
    }

    private function elegir(array $opciones): mixed
    {
        return $opciones[$this->azar(0, count($opciones) - 1)];
    }

    private function contar(string $tabla): void
    {
        $this->insertados[$tabla] = ($this->insertados[$tabla] ?? 0) + 1;
    }

    private function informar(): void
    {
        if (! $this->command) {
            return;
        }

        ksort($this->insertados);
        $this->command->info('Población de prueba: filas insertadas en esta ejecución');
        $this->command->table(['Tabla', 'Filas nuevas'], collect($this->insertados)->map(fn ($n, $t) => [$t, $n])->values()->all());

        foreach ($this->omitidos as $motivo => $n) {
            $this->command->warn("Omitidas: {$n} {$motivo}");
        }

        if (! $this->insertados) {
            $this->command->info('No se insertó nada: los datos de prueba ya existían.');
        }
    }
}
