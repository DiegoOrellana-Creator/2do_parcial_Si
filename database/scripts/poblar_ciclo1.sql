-- =============================================================================
--  Clínica Santa Cruz — Datos de PRUEBA del Ciclo 1 (PostgreSQL, base clinica_db)
-- =============================================================================
--
--  Contenido: roles, permisos, especialidades, perfiles de médicos, pacientes y
--  recepción, asociaciones médico–especialidad, horarios de atención, un bloqueo
--  de agenda, citas de prueba y su historial de estados.
--
--  NO incluye pagos, notificaciones ni nada del Ciclo 2.
--
--  ORDEN DE EJECUCIÓN (revisar antes; nada de esto se ejecutó al generar el archivo):
--
--    0. Copia de seguridad:
--         pg_dump -U <usuario> -h <host> -Fc -f clinica_db_antes_ciclo1.dump clinica_db
--    1. (Opcional) Cuentas adicionales con contraseña cifrada por Laravel:
--         PowerShell: $env:SEED_TEST_PASSWORD = '...'; php artisan db:seed --class=CuentasPruebaCiclo1Seeder
--       Este script no contiene hashes de contraseñas. Si no se ejecuta el seeder,
--       las filas que dependen de esas cuentas (medico02…05, recepcion01,
--       paciente02…09) simplemente no se insertan y solo se usan las cuentas que
--       ya existen: admin, medico01 y paciente01.
--    2. Este script:
--         psql -U <usuario> -h <host> -d clinica_db -v ON_ERROR_STOP=1 -f database/scripts/poblar_ciclo1.sql
--
--  GARANTÍAS:
--    * Usa solo tablas y columnas existentes; no modifica el esquema.
--    * Solo hace INSERT. No hay UPDATE ni DELETE: las cuentas, perfiles y
--      asignaciones existentes se conservan tal como están (incluidas las
--      credenciales de admin, medico01 y paciente01).
--    * Idempotente: cada INSERT se protege con ON CONFLICT DO NOTHING sobre una
--      clave única real o con NOT EXISTS sobre una clave natural, así que puede
--      ejecutarse varias veces sin duplicar filas.
--    * Todo va en una transacción: si algo falla, no queda nada a medias.
--    * Las cuentas se buscan por username, nunca por id fijo.
--
--  FECHAS: relativas al día en que se ejecuta el script (CURRENT_DATE), tomando
--  como referencia el lunes de la semana actual:
--    semana pasada  (lunes a viernes) → Atendida, No asistió, Cancelada
--    semana próxima (lunes a viernes) → Programada, Confirmada, Cancelada
--  Así siempre hay citas pasadas y futuras, sea cual sea el día de ejecución.
--  Los códigos de cita (CIT-PRUEBA-0001…) son fijos: si el script se vuelve a
--  ejecutar otro día, las citas ya existentes no se duplican ni se mueven.
--
--  Días de la semana (horario_atencion.dia_semana, ISO): 1 = lunes … 7 = domingo.
-- =============================================================================

-- El archivo está en UTF-8. Sin esta línea, psql en Windows lo lee como WIN1252
-- y guarda los acentos mal codificados (por ejemplo «MÃ©dico» en vez de «Médico»).
SET client_encoding = 'UTF8';

BEGIN;

-- -----------------------------------------------------------------------------
-- 0. Comprobación previa: la cuenta administrativa debe existir y estar activa.
--    Si no, se aborta la transacción y no se escribe nada.
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin' AND estado = 'Activo') THEN
        RAISE EXCEPTION 'La cuenta «admin» no existe o no está activa: no se escribió nada.';
    END IF;
END
$$;


-- -----------------------------------------------------------------------------
-- 1. Roles
--    rol.nombre no tiene restricción UNIQUE, por eso se usa NOT EXISTS.
--    «Médico» y «Paciente» identifican perfiles; no otorgan permisos de personal.
-- -----------------------------------------------------------------------------
INSERT INTO rol (nombre, descripcion)
SELECT v.nombre, v.descripcion
FROM (VALUES
    ('Administrador', 'Administración general de la clínica.'),
    ('Recepcionista', 'Recepción: pacientes, disponibilidad y registro y cancelación de citas.'),
    ('Médico',        'Personal médico de la clínica.'),
    ('Paciente',      'Pacientes registrados en la clínica.')
) AS v(nombre, descripcion)
WHERE NOT EXISTS (SELECT 1 FROM rol r WHERE r.nombre = v.nombre);


-- -----------------------------------------------------------------------------
-- 2. Permisos (los mismos códigos que comprueban las rutas con can:…)
--    permiso.codigo es UNIQUE: un permiso que ya existe conserva su nombre.
-- -----------------------------------------------------------------------------
INSERT INTO permiso (codigo, nombre) VALUES
    ('pacientes.ver',           'Ver pacientes'),
    ('pacientes.crear',         'Registrar pacientes'),
    ('pacientes.editar',        'Editar pacientes'),
    ('pacientes.eliminar',      'Eliminar pacientes'),
    ('especialidades.ver',      'Ver especialidades'),
    ('especialidades.crear',    'Registrar especialidades'),
    ('especialidades.editar',   'Editar especialidades'),
    ('especialidades.eliminar', 'Eliminar especialidades'),
    ('medicos.ver',             'Ver médicos'),
    ('medicos.crear',           'Registrar médicos'),
    ('medicos.editar',          'Editar médicos'),
    ('medicos.eliminar',        'Eliminar médicos'),
    ('horarios.ver',            'Ver horarios de atención'),
    ('horarios.crear',          'Registrar horarios de atención'),
    ('horarios.editar',         'Editar horarios de atención'),
    ('horarios.eliminar',       'Eliminar horarios de atención'),
    ('disponibilidad.ver',      'Consultar disponibilidad de citas'),
    ('citas.ver',               'Ver citas'),
    ('citas.crear',             'Registrar citas'),
    ('citas.cancelar',          'Cancelar citas'),
    ('agenda.ver',              'Consultar la agenda médica')
ON CONFLICT (codigo) DO NOTHING;


-- -----------------------------------------------------------------------------
-- 3. Permisos por rol (rol_permiso, clave primaria id_rol + id_permiso)
--    Administrador: todos los permisos del Ciclo 1.
--    Recepcionista: consulta catálogos, gestiona pacientes (sin eliminar) y citas.
--    Si hubiera roles repetidos con el mismo nombre se usa el de menor id_rol,
--    igual que los seeders del proyecto.
-- -----------------------------------------------------------------------------
INSERT INTO rol_permiso (id_rol, id_permiso)
SELECT (SELECT MIN(r.id_rol) FROM rol r WHERE r.nombre = v.rol), p.id_permiso
FROM (VALUES
    ('Administrador', 'pacientes.ver'),
    ('Administrador', 'pacientes.crear'),
    ('Administrador', 'pacientes.editar'),
    ('Administrador', 'pacientes.eliminar'),
    ('Administrador', 'especialidades.ver'),
    ('Administrador', 'especialidades.crear'),
    ('Administrador', 'especialidades.editar'),
    ('Administrador', 'especialidades.eliminar'),
    ('Administrador', 'medicos.ver'),
    ('Administrador', 'medicos.crear'),
    ('Administrador', 'medicos.editar'),
    ('Administrador', 'medicos.eliminar'),
    ('Administrador', 'horarios.ver'),
    ('Administrador', 'horarios.crear'),
    ('Administrador', 'horarios.editar'),
    ('Administrador', 'horarios.eliminar'),
    ('Administrador', 'disponibilidad.ver'),
    ('Administrador', 'citas.ver'),
    ('Administrador', 'citas.crear'),
    ('Administrador', 'citas.cancelar'),
    ('Administrador', 'agenda.ver'),
    ('Recepcionista', 'pacientes.ver'),
    ('Recepcionista', 'pacientes.crear'),
    ('Recepcionista', 'pacientes.editar'),
    ('Recepcionista', 'especialidades.ver'),
    ('Recepcionista', 'medicos.ver'),
    ('Recepcionista', 'horarios.ver'),
    ('Recepcionista', 'disponibilidad.ver'),
    ('Recepcionista', 'citas.ver'),
    ('Recepcionista', 'citas.crear'),
    ('Recepcionista', 'citas.cancelar'),
    ('Recepcionista', 'agenda.ver')
) AS v(rol, codigo)
JOIN permiso p ON p.codigo = v.codigo
ON CONFLICT (id_rol, id_permiso) DO NOTHING;


-- -----------------------------------------------------------------------------
-- 4. Especialidades
--    especialidad.nombre no es UNIQUE: se evita el duplicado con NOT EXISTS.
--    Oftalmología queda Inactiva para probar que no se ofrece al registrar citas.
-- -----------------------------------------------------------------------------
INSERT INTO especialidad (nombre, descripcion, estado)
SELECT v.nombre, v.descripcion, v.estado
FROM (VALUES
    ('Medicina General',          'Atención primaria, diagnóstico inicial y derivación.',      'Activo'),
    ('Pediatría',                 'Atención médica de niños y adolescentes.',                  'Activo'),
    ('Cardiología',               'Diagnóstico y tratamiento de enfermedades del corazón.',     'Activo'),
    ('Dermatología',              'Enfermedades de la piel, el cabello y las uñas.',            'Activo'),
    ('Ginecología y Obstetricia', 'Salud femenina, embarazo y parto.',                          'Activo'),
    ('Traumatología y Ortopedia', 'Lesiones y enfermedades del sistema musculoesquelético.',    'Activo'),
    ('Neurología',                'Trastornos del sistema nervioso.',                           'Activo'),
    ('Oftalmología',              'Salud visual y enfermedades de los ojos.',                   'Inactivo')
) AS v(nombre, descripcion, estado)
WHERE NOT EXISTS (SELECT 1 FROM especialidad e WHERE e.nombre = v.nombre);


-- -----------------------------------------------------------------------------
-- 5. Asignación de roles a cuentas (usuario_rol, clave primaria id_usuario + id_rol)
--    Una asignación existente no se toca (tampoco se reactiva si está Inactiva).
--    Las cuentas que no existan se omiten por el JOIN.
-- -----------------------------------------------------------------------------
INSERT INTO usuario_rol (id_usuario, id_rol, estado)
SELECT u.id, (SELECT MIN(r.id_rol) FROM rol r WHERE r.nombre = v.rol), 'Activo'
FROM (VALUES
    ('admin',       'Administrador'),
    ('recepcion01', 'Recepcionista'),
    ('medico01',    'Médico'),
    ('medico02',    'Médico'),
    ('medico03',    'Médico'),
    ('medico04',    'Médico'),
    ('medico05',    'Médico'),
    ('paciente01',  'Paciente'),
    ('paciente02',  'Paciente'),
    ('paciente03',  'Paciente'),
    ('paciente04',  'Paciente'),
    ('paciente05',  'Paciente'),
    ('paciente06',  'Paciente'),
    ('paciente07',  'Paciente'),
    ('paciente08',  'Paciente'),
    ('paciente09',  'Paciente')
) AS v(username, rol)
JOIN users u ON u.username = v.username
ON CONFLICT (id_usuario, id_rol) DO NOTHING;

-- Fila de personal administrativo para admin (código ADM-001 si está libre).
INSERT INTO administrador (id_administrador, codigo)
SELECT u.id,
       CASE WHEN EXISTS (SELECT 1 FROM administrador a WHERE a.codigo = 'ADM-001') THEN NULL ELSE 'ADM-001' END
FROM users u
WHERE u.username = 'admin'
ON CONFLICT (id_administrador) DO NOTHING;

-- Fila de recepción para recepcion01 (solo si el seeder creó la cuenta).
INSERT INTO recepcionista (id_recepcionista, codigo)
SELECT u.id, 'REC-PRB-01'
FROM users u
WHERE u.username = 'recepcion01'
  AND NOT EXISTS (SELECT 1 FROM recepcionista r WHERE r.codigo = 'REC-PRB-01')
ON CONFLICT (id_recepcionista) DO NOTHING;


-- -----------------------------------------------------------------------------
-- 6. Médicos adicionales (tabla medico; la cuenta la crea CuentasPruebaCiclo1Seeder)
--    medico01 ya tiene su fila y NO se modifica.
--    medico05 tiene la cuenta Inactiva: sirve para comprobar que un médico
--    inactivo no aparece en la disponibilidad aunque tenga horario.
-- -----------------------------------------------------------------------------
INSERT INTO medico (id_medico, matricula, codigo)
SELECT u.id, v.matricula, v.codigo
FROM (VALUES
    ('medico02', 'MAT-PRB-002', 'MED-PRB-002'),
    ('medico03', 'MAT-PRB-003', 'MED-PRB-003'),
    ('medico04', 'MAT-PRB-004', 'MED-PRB-004'),
    ('medico05', 'MAT-PRB-005', 'MED-PRB-005')
) AS v(username, matricula, codigo)
JOIN users u ON u.username = v.username
WHERE NOT EXISTS (SELECT 1 FROM medico m WHERE m.matricula = v.matricula OR m.codigo = v.codigo)
ON CONFLICT (id_medico) DO NOTHING;


-- -----------------------------------------------------------------------------
-- 7. Pacientes adicionales (tabla paciente; la cuenta la crea el seeder)
--    paciente01 ya tiene su fila y NO se modifica.
--    paciente08: bloqueado con 3 inasistencias (prueba la regla de bloqueo).
--    paciente09: 1 inasistencia, coherente con su cita «No asistió» de la sección 11.
-- -----------------------------------------------------------------------------
INSERT INTO paciente (id_paciente, nro_ficha, inasistencia, bloqueado)
SELECT u.id, v.nro_ficha, v.inasistencia, v.bloqueado
FROM (VALUES
    ('paciente02', 'F-PRB-0002', 0, false),
    ('paciente03', 'F-PRB-0003', 0, false),
    ('paciente04', 'F-PRB-0004', 0, false),
    ('paciente05', 'F-PRB-0005', 0, false),
    ('paciente06', 'F-PRB-0006', 0, false),
    ('paciente07', 'F-PRB-0007', 0, false),
    ('paciente08', 'F-PRB-0008', 3, true),
    ('paciente09', 'F-PRB-0009', 1, false)
) AS v(username, nro_ficha, inasistencia, bloqueado)
JOIN users u ON u.username = v.username
WHERE NOT EXISTS (SELECT 1 FROM paciente p WHERE p.nro_ficha = v.nro_ficha)
ON CONFLICT (id_paciente) DO NOTHING;

-- Información médica de algunos pacientes nuevos (clave primaria id_paciente).
INSERT INTO informacion_medica (id_paciente, tipo_sangre, alergias, enfermedades_cronicas, medicamentos, antecedentes)
SELECT p.id_paciente, v.tipo_sangre, v.alergias, v.cronicas, v.medicamentos, v.antecedentes
FROM (VALUES
    ('paciente02', 'O+', 'Penicilina', NULL,                    NULL,              'Apendicectomía'),
    ('paciente04', 'A+', NULL,         'Hipertensión arterial', 'Losartán 50 mg',  'Antecedentes familiares de diabetes'),
    ('paciente05', 'B+', 'Polen',      'Hipotiroidismo',        'Levotiroxina 50 mcg', NULL)
) AS v(username, tipo_sangre, alergias, cronicas, medicamentos, antecedentes)
JOIN users u ON u.username = v.username
JOIN paciente p ON p.id_paciente = u.id
ON CONFLICT (id_paciente) DO NOTHING;

-- Contactos de emergencia (sin clave natural: se evita el duplicado con NOT EXISTS).
INSERT INTO contacto_emergencia (id_paciente, nombre, parentesco, telefono)
SELECT p.id_paciente, v.nombre, v.parentesco, v.telefono
FROM (VALUES
    ('paciente02', 'Luis Rojas Vaca',        'Hermano', '70300002'),
    ('paciente03', 'Andrea Peña Soliz',      'Madre',   '70300003'),
    ('paciente04', 'Teresa Montaño Ribera',  'Esposa',  '70300004')
) AS v(username, nombre, parentesco, telefono)
JOIN users u ON u.username = v.username
JOIN paciente p ON p.id_paciente = u.id
WHERE NOT EXISTS (
    SELECT 1 FROM contacto_emergencia c WHERE c.id_paciente = p.id_paciente AND c.nombre = v.nombre
);


-- -----------------------------------------------------------------------------
-- 8. Médicos y especialidades (especialidad_medico, clave primaria id_medico + id_especialidad)
-- -----------------------------------------------------------------------------
INSERT INTO especialidad_medico (id_medico, id_especialidad)
SELECT m.id_medico, (SELECT MIN(e.id_especialidad) FROM especialidad e WHERE e.nombre = v.especialidad)
FROM (VALUES
    ('medico01', 'Medicina General'),
    ('medico01', 'Cardiología'),
    ('medico02', 'Pediatría'),
    ('medico02', 'Medicina General'),
    ('medico03', 'Cardiología'),
    ('medico04', 'Dermatología'),
    ('medico04', 'Ginecología y Obstetricia'),
    ('medico05', 'Traumatología y Ortopedia')
) AS v(username, especialidad)
JOIN users u ON u.username = v.username
JOIN medico m ON m.id_medico = u.id
ON CONFLICT (id_medico, id_especialidad) DO NOTHING;


-- -----------------------------------------------------------------------------
-- 9. Horarios de atención (duracion_cita en minutos)
--    No hay clave única: se omite el bloque si se superpone con otro horario del
--    mismo médico ese día (la misma regla que aplica HorarioRequest).
-- -----------------------------------------------------------------------------
INSERT INTO horario_atencion (id_medico, dia_semana, hora_inicio, hora_final, duracion_cita)
SELECT m.id_medico, v.dia, v.inicio, v.fin, v.duracion
FROM (VALUES
    ('medico01', 1, TIME '08:00', TIME '12:00', 30),   -- lunes
    ('medico01', 3, TIME '08:00', TIME '12:00', 30),   -- miércoles
    ('medico01', 5, TIME '14:00', TIME '18:00', 30),   -- viernes
    ('medico02', 1, TIME '14:00', TIME '18:00', 20),   -- lunes
    ('medico02', 2, TIME '08:00', TIME '12:00', 20),   -- martes
    ('medico02', 4, TIME '08:00', TIME '12:00', 20),   -- jueves
    ('medico03', 2, TIME '14:00', TIME '18:00', 30),   -- martes
    ('medico03', 4, TIME '14:00', TIME '18:00', 30),   -- jueves
    ('medico04', 1, TIME '08:00', TIME '11:00', 30),   -- lunes
    ('medico04', 3, TIME '14:00', TIME '17:00', 30),   -- miércoles
    ('medico05', 5, TIME '08:00', TIME '12:00', 30)    -- viernes (médico inactivo)
) AS v(username, dia, inicio, fin, duracion)
JOIN users u ON u.username = v.username
JOIN medico m ON m.id_medico = u.id
WHERE NOT EXISTS (
    SELECT 1 FROM horario_atencion h
    WHERE h.id_medico = m.id_medico
      AND h.dia_semana = v.dia
      AND h.hora_inicio < v.fin
      AND h.hora_final > v.inicio
);


-- -----------------------------------------------------------------------------
-- 10. Bloqueo de agenda: medico01 no atiende el miércoles de la semana próxima
--     de 10:00 a 12:00. La disponibilidad debe mostrar esas franjas como bloqueadas.
--     Se identifica por su motivo: en otra ejecución no se crea un segundo bloqueo.
-- -----------------------------------------------------------------------------
INSERT INTO bloqueo_agenda (id_medico, fecha, hora_inicio, hora_final, motivo)
SELECT m.id_medico, (date_trunc('week', CURRENT_DATE)::date + 7 + 2), TIME '10:00', TIME '12:00', 'Capacitación interna (datos de prueba)'
FROM users u
JOIN medico m ON m.id_medico = u.id
WHERE u.username = 'medico01'
  AND NOT EXISTS (
      SELECT 1 FROM bloqueo_agenda b
      WHERE b.id_medico = m.id_medico AND b.motivo = 'Capacitación interna (datos de prueba)'
  );


-- -----------------------------------------------------------------------------
-- 11. Citas de prueba (citas.codigo es UNIQUE)
--     Cada cita cae en una franja del horario de su médico, el médico ejerce la
--     especialidad, ningún médico ni paciente tiene dos citas a la misma hora y
--     ninguna cae en el bloqueo de la sección 10.
--     Las canceladas registran motivo, fecha y quién canceló (admin).
--     Se omite la cita si falta el paciente o el médico, o si el médico no
--     ejerce la especialidad.
--
--     semana: -1 = semana pasada, 1 = semana próxima; dia: 1 = lunes … 5 = viernes
--     (coincide con horario_atencion.dia_semana del médico).
--     Fecha de creación: semana pasada → 4 días antes de la cita; semana próxima →
--     ayer, para que nunca quede en el futuro. Las cancelaciones de la semana pasada
--     son de la víspera de la cita; las de la semana próxima, de hace una hora.
-- -----------------------------------------------------------------------------
INSERT INTO citas (codigo, id_paciente, id_medico, id_especialidad, fecha_cita, hora_inicio, hora_fin,
                   motivo, estado, fecha_creacion, motivo_cancelacion, fecha_cancelacion, cancelado_por)
SELECT v.codigo, pa.id_paciente, me.id_medico, esp.id_especialidad, f.fecha, v.inicio, v.fin,
       v.motivo, v.estado,
       CASE WHEN v.semana < 0 THEN (f.fecha - 4) + v.hora_creacion ELSE (CURRENT_DATE - 1) + v.hora_creacion END,
       v.motivo_cancelacion,
       CASE WHEN v.estado = 'Cancelada' THEN
            CASE WHEN v.semana < 0 THEN (f.fecha - 1) + TIME '17:30' ELSE LOCALTIMESTAMP(0) - INTERVAL '1 hour' END
       END,
       CASE WHEN v.estado = 'Cancelada' THEN (SELECT id FROM users WHERE username = 'admin') END
FROM (VALUES
    -- Semana pasada
    ('CIT-PRUEBA-0001', 'paciente02', 'medico01', 'Medicina General',          -1, 1, TIME '08:00', TIME '08:30', 'Control de rutina',                     'Atendida',   TIME '09:10', NULL::text),
    ('CIT-PRUEBA-0002', 'paciente09', 'medico01', 'Cardiología',               -1, 1, TIME '08:30', TIME '09:00', 'Primera consulta',                      'No asistió', TIME '10:25', NULL),
    ('CIT-PRUEBA-0003', 'paciente03', 'medico02', 'Pediatría',                 -1, 2, TIME '08:00', TIME '08:20', 'Evaluación preventiva',                 'Atendida',   TIME '08:40', NULL),
    ('CIT-PRUEBA-0004', 'paciente04', 'medico04', 'Dermatología',              -1, 3, TIME '14:00', TIME '14:30', 'Consulta por malestar general',         'Cancelada',  TIME '11:05', 'El paciente no puede asistir por motivos laborales.'),
    -- Semana próxima
    ('CIT-PRUEBA-0005', 'paciente01', 'medico01', 'Medicina General',           1, 1, TIME '08:00', TIME '08:30', 'Control de rutina',                     'Programada', TIME '09:00', NULL),
    ('CIT-PRUEBA-0006', 'paciente02', 'medico01', 'Cardiología',                1, 1, TIME '08:30', TIME '09:00', 'Revisión de resultados de laboratorio', 'Confirmada', TIME '09:15', NULL),
    ('CIT-PRUEBA-0007', 'paciente03', 'medico02', 'Pediatría',                  1, 1, TIME '14:00', TIME '14:20', 'Control de tratamiento',                'Programada', TIME '10:00', NULL),
    ('CIT-PRUEBA-0008', 'paciente05', 'medico03', 'Cardiología',                1, 2, TIME '14:00', TIME '14:30', 'Primera consulta',                      'Programada', TIME '10:30', NULL),
    ('CIT-PRUEBA-0009', 'paciente06', 'medico02', 'Medicina General',           1, 2, TIME '08:20', TIME '08:40', 'Consulta por dolor persistente',        'Programada', TIME '11:00', NULL),
    ('CIT-PRUEBA-0010', 'paciente07', 'medico04', 'Ginecología y Obstetricia',  1, 3, TIME '14:30', TIME '15:00', 'Control anual',                         'Programada', TIME '11:20', NULL),
    ('CIT-PRUEBA-0011', 'paciente04', 'medico01', 'Medicina General',           1, 3, TIME '08:00', TIME '08:30', 'Renovación de receta',                  'Cancelada',  TIME '15:00', 'El paciente se recuperó y ya no necesita la consulta.'),
    ('CIT-PRUEBA-0012', 'paciente06', 'medico03', 'Cardiología',                1, 4, TIME '15:00', TIME '15:30', 'Seguimiento postoperatorio',            'Programada', TIME '12:00', NULL),
    ('CIT-PRUEBA-0013', 'paciente05', 'medico01', 'Cardiología',                1, 5, TIME '14:00', TIME '14:30', 'Control de tratamiento',                'Programada', TIME '12:30', NULL)
) AS v(codigo, paciente, medico, especialidad, semana, dia, inicio, fin, motivo, estado, hora_creacion, motivo_cancelacion)
CROSS JOIN LATERAL (
    SELECT date_trunc('week', CURRENT_DATE)::date + v.semana * 7 + (v.dia - 1) AS fecha
) f
JOIN users up    ON up.username = v.paciente
JOIN paciente pa ON pa.id_paciente = up.id
JOIN users um    ON um.username = v.medico
JOIN medico me   ON me.id_medico = um.id
JOIN LATERAL (
    SELECT MIN(e.id_especialidad) AS id_especialidad FROM especialidad e WHERE e.nombre = v.especialidad
) esp ON esp.id_especialidad IS NOT NULL
WHERE EXISTS (
    SELECT 1 FROM especialidad_medico em
    WHERE em.id_medico = me.id_medico AND em.id_especialidad = esp.id_especialidad
)
ON CONFLICT (codigo) DO NOTHING;


-- -----------------------------------------------------------------------------
-- 12. Historial de estados de las citas
--     Toda cita empieza en «Programada» (estado_anterior NULL); las que cambiaron
--     tienen un segundo registro. registrado_por = admin.
--     Las fechas salen de la propia cita (sección 11): el registro inicial usa
--     fecha_creacion, la cancelación usa fecha_cancelacion y la asistencia o
--     inasistencia, la hora de la cita. La confirmación es de hace dos horas.
--     Se omite un registro si esa cita ya tiene uno con el mismo estado nuevo.
-- -----------------------------------------------------------------------------
INSERT INTO historial_estado_cita (id_cita, estado_anterior, estado_nuevo, fecha_hora, motivo, registrado_por)
SELECT c.id_cita, v.anterior, v.nuevo,
       CASE v.nuevo
           WHEN 'Programada' THEN c.fecha_creacion
           WHEN 'Cancelada'  THEN c.fecha_cancelacion
           WHEN 'Confirmada' THEN LOCALTIMESTAMP(0) - INTERVAL '2 hours'
           WHEN 'Atendida'   THEN c.fecha_cita + c.hora_fin
           ELSE c.fecha_cita + c.hora_fin + INTERVAL '15 minutes'   -- No asistió
       END,
       v.motivo, (SELECT id FROM users WHERE username = 'admin')
FROM (VALUES
    ('CIT-PRUEBA-0001', NULL::varchar, 'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0001', 'Programada',  'Atendida',   'Consulta realizada.'),
    ('CIT-PRUEBA-0002', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0002', 'Programada',  'No asistió', 'El paciente no se presentó.'),
    ('CIT-PRUEBA-0003', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0003', 'Programada',  'Atendida',   'Consulta realizada.'),
    ('CIT-PRUEBA-0004', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0004', 'Programada',  'Cancelada',  'El paciente no puede asistir por motivos laborales.'),
    ('CIT-PRUEBA-0005', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0006', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0006', 'Programada',  'Confirmada', 'El paciente confirmó su asistencia.'),
    ('CIT-PRUEBA-0007', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0008', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0009', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0010', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0011', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0011', 'Programada',  'Cancelada',  'El paciente se recuperó y ya no necesita la consulta.'),
    ('CIT-PRUEBA-0012', NULL,          'Programada', 'Cita registrada.'),
    ('CIT-PRUEBA-0013', NULL,          'Programada', 'Cita registrada.')
) AS v(codigo, anterior, nuevo, motivo)
JOIN citas c ON c.codigo = v.codigo
WHERE NOT EXISTS (
    SELECT 1 FROM historial_estado_cita h WHERE h.id_cita = c.id_cita AND h.estado_nuevo = v.nuevo
);


-- Revisar el resultado de las consultas de abajo y luego confirmar con COMMIT.
-- (Para descartar todo, sustituir COMMIT por ROLLBACK.)
COMMIT;


-- =============================================================================
-- 13. Consultas de verificación (solo lectura)
-- =============================================================================

-- 13.1 Filas por tabla
SELECT 'rol' AS tabla, COUNT(*) AS filas FROM rol
UNION ALL SELECT 'permiso', COUNT(*) FROM permiso
UNION ALL SELECT 'rol_permiso', COUNT(*) FROM rol_permiso
UNION ALL SELECT 'usuario_rol', COUNT(*) FROM usuario_rol
UNION ALL SELECT 'administrador', COUNT(*) FROM administrador
UNION ALL SELECT 'recepcionista', COUNT(*) FROM recepcionista
UNION ALL SELECT 'especialidad', COUNT(*) FROM especialidad
UNION ALL SELECT 'medico', COUNT(*) FROM medico
UNION ALL SELECT 'paciente', COUNT(*) FROM paciente
UNION ALL SELECT 'especialidad_medico', COUNT(*) FROM especialidad_medico
UNION ALL SELECT 'horario_atencion', COUNT(*) FROM horario_atencion
UNION ALL SELECT 'bloqueo_agenda', COUNT(*) FROM bloqueo_agenda
UNION ALL SELECT 'citas', COUNT(*) FROM citas
UNION ALL SELECT 'historial_estado_cita', COUNT(*) FROM historial_estado_cita;

-- 13.2 Las cuentas existentes siguen igual (comparar con los valores previos)
SELECT id, username, email, estado, LEFT(password, 7) AS inicio_hash, updated_at
FROM users
WHERE username IN ('admin', 'medico01', 'paciente01')
ORDER BY id;

-- 13.3 Permisos efectivos de admin (deben ser los 21 del Ciclo 1)
SELECT p.codigo
FROM users u
JOIN usuario_rol ur ON ur.id_usuario = u.id AND ur.estado = 'Activo'
JOIN rol_permiso rp ON rp.id_rol = ur.id_rol
JOIN permiso p      ON p.id_permiso = rp.id_permiso
WHERE u.username = 'admin'
GROUP BY p.codigo
ORDER BY p.codigo;

-- 13.4 Roles y número de permisos por rol
SELECT r.id_rol, r.nombre, COUNT(rp.id_permiso) AS permisos
FROM rol r
LEFT JOIN rol_permiso rp ON rp.id_rol = r.id_rol
GROUP BY r.id_rol, r.nombre
ORDER BY r.id_rol;

-- 13.5 Médicos, estado de su cuenta y especialidades
SELECT u.username, u.nombre || ' ' || u.apellido AS medico, u.estado, m.matricula,
       STRING_AGG(e.nombre, ', ' ORDER BY e.nombre) AS especialidades
FROM medico m
JOIN users u ON u.id = m.id_medico
LEFT JOIN especialidad_medico em ON em.id_medico = m.id_medico
LEFT JOIN especialidad e ON e.id_especialidad = em.id_especialidad
GROUP BY u.username, u.nombre, u.apellido, u.estado, m.matricula
ORDER BY u.username;

-- 13.6 Horarios de atención
SELECT u.username, h.dia_semana, h.hora_inicio, h.hora_final, h.duracion_cita
FROM horario_atencion h
JOIN users u ON u.id = h.id_medico
ORDER BY u.username, h.dia_semana, h.hora_inicio;

-- 13.7 Citas de prueba
SELECT c.codigo, c.fecha_cita, c.hora_inicio, c.hora_fin, c.estado,
       up.username AS paciente, um.username AS medico, e.nombre AS especialidad
FROM citas c
JOIN users up ON up.id = c.id_paciente
JOIN users um ON um.id = c.id_medico
JOIN especialidad e ON e.id_especialidad = c.id_especialidad
WHERE c.codigo LIKE 'CIT-PRUEBA-%'
ORDER BY c.fecha_cita, c.hora_inicio;

-- 13.8 Historial: cada cita debe tener su registro inicial y el estado final
--      del historial debe coincidir con el estado de la cita (esperado: 0 filas)
SELECT c.codigo, c.estado, ultimo.estado_nuevo AS ultimo_historial
FROM citas c
LEFT JOIN LATERAL (
    SELECT h.estado_nuevo FROM historial_estado_cita h
    WHERE h.id_cita = c.id_cita
    ORDER BY h.fecha_hora DESC, h.id_historial DESC
    LIMIT 1
) ultimo ON true
WHERE c.codigo LIKE 'CIT-PRUEBA-%'
  AND ultimo.estado_nuevo IS DISTINCT FROM c.estado;

-- 13.9 Choques de horario entre citas que ocupan franja (esperado: 0 filas)
SELECT a.codigo, b.codigo AS choca_con
FROM citas a
JOIN citas b ON a.id_cita < b.id_cita
            AND a.fecha_cita = b.fecha_cita
            AND (a.id_medico = b.id_medico OR a.id_paciente = b.id_paciente)
            AND a.hora_inicio < b.hora_fin AND b.hora_inicio < a.hora_fin
WHERE a.estado <> 'Cancelada' AND b.estado <> 'Cancelada';

-- 13.10 Citas fuera del horario del médico (esperado: 0 filas)
SELECT c.codigo
FROM citas c
WHERE c.codigo LIKE 'CIT-PRUEBA-%'
  AND NOT EXISTS (
      SELECT 1 FROM horario_atencion h
      WHERE h.id_medico = c.id_medico
        AND h.dia_semana = EXTRACT(ISODOW FROM c.fecha_cita)
        AND c.hora_inicio >= h.hora_inicio
        AND c.hora_fin <= h.hora_final
  );

-- 13.11 Citas de prueba pasadas y futuras por estado
--       (esperado: pasadas → Atendida 2, Cancelada 1, No asistió 1;
--                  futuras → Programada 7, Confirmada 1, Cancelada 1)
SELECT CASE WHEN c.fecha_cita < CURRENT_DATE THEN 'pasada' ELSE 'futura' END AS momento,
       c.estado, COUNT(*) AS citas
FROM citas c
WHERE c.codigo LIKE 'CIT-PRUEBA-%'
GROUP BY 1, 2
ORDER BY 1 DESC, 2;

-- 13.12 Fechas del historial coherentes: nada en el futuro y ningún cambio de
--       estado anterior al registro de la cita (esperado: 0 filas)
SELECT c.codigo, h.estado_nuevo, h.fecha_hora, c.fecha_creacion
FROM historial_estado_cita h
JOIN citas c ON c.id_cita = h.id_cita
WHERE c.codigo LIKE 'CIT-PRUEBA-%'
  AND (h.fecha_hora > LOCALTIMESTAMP OR h.fecha_hora < c.fecha_creacion);
