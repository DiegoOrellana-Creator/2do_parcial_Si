-- Aplica con psql la migración 2026_11_04_000000_add_precio_to_especialidad_table, para bases en las
-- que «php artisan migrate» no puede conectarse (por ejemplo, Render desde el PHP de XAMPP: su
-- librería de PostgreSQL no envía SNI y Render responde «No SNI information found»).
--
-- Hace lo mismo que la migración: añade especialidad.precio numeric(10,2) NULL y la anota en la
-- tabla migrations, para que Laravel no intente aplicarla otra vez. Es idempotente.
--
-- Uso: psql "<URL de la base>" -v ON_ERROR_STOP=1 -f database/scripts/migracion_precio.sql

SET client_encoding = 'UTF8';

BEGIN;

ALTER TABLE especialidad ADD COLUMN IF NOT EXISTS precio numeric(10, 2) NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_11_04_000000_add_precio_to_especialidad_table', COALESCE(MAX(batch), 0) + 1
FROM migrations
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migration = '2026_11_04_000000_add_precio_to_especialidad_table'
);

-- Resultado: la columna existe y la migración quedó anotada.
SELECT column_name, data_type, numeric_precision, numeric_scale, is_nullable
FROM information_schema.columns
WHERE table_name = 'especialidad' AND column_name = 'precio';

SELECT migration, batch FROM migrations ORDER BY id;

COMMIT;
