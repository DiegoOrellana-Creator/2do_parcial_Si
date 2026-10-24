-- =============================================================================
--  Clínica Santa Cruz — Reparación de textos con acentos mal codificados
-- =============================================================================
--
--  Causa: poblar_ciclo1.sql (UTF-8) se ejecutó con psql en la codificación de
--  Windows (WIN1252), así que cada letra acentuada se guardó como dos caracteres:
--  «Médico» → «MÃ©dico», «No asistió» → «No asistiÃ³», «Peña» → «PeÃ±a».
--  Además, cinco especialidades tienen un guion suave invisible (U+00AD) después
--  de la «í» (por ejemplo «Pediatrí­a»), restos de una corrección manual parcial.
--
--  Efectos en la aplicación:
--    * el rol «MÃ©dico» no coincide con «Médico» (el menú ya se corrigió en el código);
--    * la cita en estado «No asistiÃ³» no se reconoce como «No asistió»: no ocupa su
--      franja en la disponibilidad y su estado no se muestra correctamente;
--    * los nombres y motivos se ven con caracteres extraños.
--
--  Qué hace: SOLO UPDATE de columnas de texto en las filas afectadas, devolviendo
--  cada valor a su texto original. No borra ni inserta filas, no toca contraseñas
--  ni la tabla users, y no cambia el esquema.
--    * Mal codificado: solo filas que contienen «Ã» o «Â» (un texto en español
--      correcto no los contiene). convert_to(valor, 'WIN1252') recupera los bytes
--      originales y convert_from(…, 'UTF8') los vuelve a leer como UTF-8.
--    * Guion suave: se elimina chr(173) del nombre y la descripción de especialidad.
--  Es idempotente: una segunda ejecución no encuentra filas que cambiar.
--
--  Ejecución (después de una copia de seguridad):
--    pg_dump -U <usuario> -h 127.0.0.1 -Fc -f clinica_db_antes_reparacion.dump clinica_db
--    psql -U <usuario> -h 127.0.0.1 -d clinica_db -v ON_ERROR_STOP=1 -f database/scripts/reparar_codificacion.sql
-- =============================================================================

SET client_encoding = 'UTF8';

BEGIN;

-- Vista previa de lo que se va a cambiar (valor actual → valor reparado)
SELECT 'rol.nombre' AS columna, nombre AS actual, convert_from(convert_to(nombre, 'WIN1252'), 'UTF8') AS reparado
FROM rol WHERE nombre LIKE '%Ã%' OR nombre LIKE '%Â%'
UNION ALL
SELECT 'citas.estado', estado, convert_from(convert_to(estado, 'WIN1252'), 'UTF8')
FROM citas WHERE estado LIKE '%Ã%' OR estado LIKE '%Â%'
UNION ALL
SELECT 'especialidad.nombre', nombre, replace(nombre, chr(173), '')
FROM especialidad WHERE nombre LIKE '%' || chr(173) || '%';

-- Roles y permisos
UPDATE rol SET nombre = convert_from(convert_to(nombre, 'WIN1252'), 'UTF8')
WHERE nombre LIKE '%Ã%' OR nombre LIKE '%Â%';

UPDATE rol SET descripcion = convert_from(convert_to(descripcion, 'WIN1252'), 'UTF8')
WHERE descripcion LIKE '%Ã%' OR descripcion LIKE '%Â%';

UPDATE permiso SET nombre = convert_from(convert_to(nombre, 'WIN1252'), 'UTF8')
WHERE nombre LIKE '%Ã%' OR nombre LIKE '%Â%';

-- Especialidades
UPDATE especialidad SET nombre = convert_from(convert_to(nombre, 'WIN1252'), 'UTF8')
WHERE nombre LIKE '%Ã%' OR nombre LIKE '%Â%';

UPDATE especialidad SET descripcion = convert_from(convert_to(descripcion, 'WIN1252'), 'UTF8')
WHERE descripcion LIKE '%Ã%' OR descripcion LIKE '%Â%';

UPDATE especialidad SET nombre = replace(nombre, chr(173), '')
WHERE nombre LIKE '%' || chr(173) || '%';

UPDATE especialidad SET descripcion = replace(descripcion, chr(173), '')
WHERE descripcion LIKE '%' || chr(173) || '%';

-- Agenda, citas e historial
UPDATE bloqueo_agenda SET motivo = convert_from(convert_to(motivo, 'WIN1252'), 'UTF8')
WHERE motivo LIKE '%Ã%' OR motivo LIKE '%Â%';

UPDATE citas SET estado = convert_from(convert_to(estado, 'WIN1252'), 'UTF8')
WHERE estado LIKE '%Ã%' OR estado LIKE '%Â%';

UPDATE citas SET motivo = convert_from(convert_to(motivo, 'WIN1252'), 'UTF8')
WHERE motivo LIKE '%Ã%' OR motivo LIKE '%Â%';

UPDATE citas SET motivo_cancelacion = convert_from(convert_to(motivo_cancelacion, 'WIN1252'), 'UTF8')
WHERE motivo_cancelacion LIKE '%Ã%' OR motivo_cancelacion LIKE '%Â%';

UPDATE historial_estado_cita SET estado_anterior = convert_from(convert_to(estado_anterior, 'WIN1252'), 'UTF8')
WHERE estado_anterior LIKE '%Ã%' OR estado_anterior LIKE '%Â%';

UPDATE historial_estado_cita SET estado_nuevo = convert_from(convert_to(estado_nuevo, 'WIN1252'), 'UTF8')
WHERE estado_nuevo LIKE '%Ã%' OR estado_nuevo LIKE '%Â%';

UPDATE historial_estado_cita SET motivo = convert_from(convert_to(motivo, 'WIN1252'), 'UTF8')
WHERE motivo LIKE '%Ã%' OR motivo LIKE '%Â%';

-- Datos de pacientes
UPDATE contacto_emergencia SET nombre = convert_from(convert_to(nombre, 'WIN1252'), 'UTF8')
WHERE nombre LIKE '%Ã%' OR nombre LIKE '%Â%';

UPDATE contacto_emergencia SET parentesco = convert_from(convert_to(parentesco, 'WIN1252'), 'UTF8')
WHERE parentesco LIKE '%Ã%' OR parentesco LIKE '%Â%';

UPDATE informacion_medica SET alergias = convert_from(convert_to(alergias, 'WIN1252'), 'UTF8')
WHERE alergias LIKE '%Ã%' OR alergias LIKE '%Â%';

UPDATE informacion_medica SET enfermedades_cronicas = convert_from(convert_to(enfermedades_cronicas, 'WIN1252'), 'UTF8')
WHERE enfermedades_cronicas LIKE '%Ã%' OR enfermedades_cronicas LIKE '%Â%';

UPDATE informacion_medica SET medicamentos = convert_from(convert_to(medicamentos, 'WIN1252'), 'UTF8')
WHERE medicamentos LIKE '%Ã%' OR medicamentos LIKE '%Â%';

UPDATE informacion_medica SET antecedentes = convert_from(convert_to(antecedentes, 'WIN1252'), 'UTF8')
WHERE antecedentes LIKE '%Ã%' OR antecedentes LIKE '%Â%';

COMMIT;

-- =============================================================================
-- Verificación (solo lectura)
-- =============================================================================

-- Debe devolver: Administrador, Médico, Paciente, Recepcionista
SELECT id_rol, nombre FROM rol ORDER BY id_rol;

-- Debe incluir «No asistió» y ningún valor con «Ã»
SELECT estado, COUNT(*) FROM citas GROUP BY estado ORDER BY estado;

-- Especialidades sin guion suave (esperado: 0 filas)
SELECT id_especialidad, nombre FROM especialidad
WHERE nombre LIKE '%' || chr(173) || '%' OR coalesce(descripcion, '') LIKE '%' || chr(173) || '%';

-- Restos de mala codificación en las tablas reparadas (esperado: 0 filas)
SELECT 'rol' AS tabla, nombre AS valor FROM rol WHERE nombre LIKE '%Ã%' OR coalesce(descripcion, '') LIKE '%Ã%'
UNION ALL SELECT 'permiso', nombre FROM permiso WHERE nombre LIKE '%Ã%'
UNION ALL SELECT 'especialidad', nombre FROM especialidad WHERE nombre LIKE '%Ã%' OR coalesce(descripcion, '') LIKE '%Ã%'
UNION ALL SELECT 'bloqueo_agenda', motivo FROM bloqueo_agenda WHERE motivo LIKE '%Ã%'
UNION ALL SELECT 'citas', codigo FROM citas WHERE estado LIKE '%Ã%' OR coalesce(motivo, '') LIKE '%Ã%' OR coalesce(motivo_cancelacion, '') LIKE '%Ã%'
UNION ALL SELECT 'historial_estado_cita', motivo FROM historial_estado_cita WHERE estado_nuevo LIKE '%Ã%' OR coalesce(estado_anterior, '') LIKE '%Ã%' OR coalesce(motivo, '') LIKE '%Ã%'
UNION ALL SELECT 'contacto_emergencia', nombre FROM contacto_emergencia WHERE nombre LIKE '%Ã%' OR coalesce(parentesco, '') LIKE '%Ã%'
UNION ALL SELECT 'informacion_medica', antecedentes FROM informacion_medica
    WHERE coalesce(alergias, '') LIKE '%Ã%' OR coalesce(enfermedades_cronicas, '') LIKE '%Ã%'
       OR coalesce(medicamentos, '') LIKE '%Ã%' OR coalesce(antecedentes, '') LIKE '%Ã%';
