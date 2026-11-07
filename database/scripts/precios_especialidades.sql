-- Precios de consulta de las especialidades que todavía no tienen precio (en bolivianos, máx. Bs 500).
-- Solo completa los vacíos: no cambia un precio ya registrado. Se puede ejecutar más de una vez.
-- Requiere la columna especialidad.precio (migración 2026_11_04_000000_add_precio_to_especialidad_table).
--
-- Uso: psql -d clinica_db -f database/scripts/precios_especialidades.sql

SET client_encoding = 'UTF8';

BEGIN;

UPDATE especialidad AS e
SET precio = p.precio
FROM (VALUES
    -- Precios que la base local ya tenía antes de este script.
    ('Cardiología',               200.00),
    ('Dermatología',              200.00),
    ('Geriatría',                 150.00),
    ('Medicina General',          150.00),
    ('Medicina Interna',          250.00),
    ('Ginecología y Obstetricia', 250.00),
    ('Reumatología',              300.00),
    ('Gastroenterología',         300.00),
    ('Neumología',                280.00),
    ('Otorrinolaringología',      250.00),
    ('Endocrinología',            300.00),
    ('Psicología Clínica',        180.00),
    ('Psiquiatría',               350.00),
    ('Urología',                  280.00),
    ('Oncología',                 450.00),
    ('Nefrología',                320.00),
    ('Neurología',                350.00),
    ('Oftalmología',              220.00),
    ('Pediatría',                 180.00),
    ('Traumatología y Ortopedia', 280.00)
) AS p(nombre, precio)
WHERE e.nombre = p.nombre
  AND e.precio IS NULL;

-- Resultado: ninguna especialidad debería quedar sin precio ni por encima de Bs 500.
SELECT nombre, precio FROM especialidad ORDER BY nombre;

COMMIT;
