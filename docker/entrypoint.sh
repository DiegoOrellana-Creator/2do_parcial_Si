#!/bin/sh
# Arranque del contenedor: prepara Laravel y lanza Apache. No ejecuta migraciones ni seeders.
set -e

cd /var/www/html

# Apache escucha en el puerto 80. Si la plataforma indica otro en PORT, se usa ese.
PUERTO="${PORT:-80}"
if [ "$PUERTO" != "80" ]; then
    sed -i "s/^Listen 80$/Listen ${PUERTO}/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PUERTO}>/" /etc/apache2/sites-available/000-default.conf
fi

# Carpetas escribibles (por si se monta un volumen vacío en storage).
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ -z "$APP_KEY" ]; then
    echo "AVISO: APP_KEY no está definida. Configúrala como variable de entorno del servicio." >&2
else
    # Cachés de configuración, rutas y vistas con las variables de entorno del servicio.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
