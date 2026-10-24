-- PROPUESTA — NO EJECUTADA
-- Equivalente SQL de 2026_10_24_000000_restringir_borrado_en_cascada_de_citas.php
-- Ejecutar solo con autorización y después de un respaldo:
--   pg_dump -U postgres -F c -f clinica_db_antes_restrict.dump clinica_db
-- PostgreSQL permite DDL dentro de transacciones: si algo falla, ROLLBACK deja todo como estaba.

BEGIN;

-- 1) Imprescindible: impedir borrar un paciente que tenga citas.
ALTER TABLE citas DROP CONSTRAINT citas_id_paciente_foreign;
ALTER TABLE citas ADD CONSTRAINT citas_id_paciente_foreign
    FOREIGN KEY (id_paciente) REFERENCES paciente (id_paciente) ON DELETE RESTRICT;

-- 2) Recomendado: impedir borrar un médico que tenga citas.
ALTER TABLE citas DROP CONSTRAINT citas_id_medico_foreign;
ALTER TABLE citas ADD CONSTRAINT citas_id_medico_foreign
    FOREIGN KEY (id_medico) REFERENCES medico (id_medico) ON DELETE RESTRICT;

-- 3) Recomendado: impedir borrar una especialidad que tenga citas.
ALTER TABLE citas DROP CONSTRAINT citas_id_especialidad_foreign;
ALTER TABLE citas ADD CONSTRAINT citas_id_especialidad_foreign
    FOREIGN KEY (id_especialidad) REFERENCES especialidad (id_especialidad) ON DELETE RESTRICT;

-- 4) Recomendado: impedir borrar una cita que tenga pagos.
ALTER TABLE pago DROP CONSTRAINT pago_id_cita_foreign;
ALTER TABLE pago ADD CONSTRAINT pago_id_cita_foreign
    FOREIGN KEY (id_cita) REFERENCES citas (id_cita) ON DELETE RESTRICT;

-- Verificación: las cuatro deben mostrar ON DELETE RESTRICT.
SELECT conrelid::regclass AS tabla, conname, pg_get_constraintdef(oid) AS definicion
FROM pg_constraint
WHERE conname IN ('citas_id_paciente_foreign', 'citas_id_medico_foreign',
                  'citas_id_especialidad_foreign', 'pago_id_cita_foreign');

-- Si la verificación es correcta: COMMIT;   Si no: ROLLBACK;
