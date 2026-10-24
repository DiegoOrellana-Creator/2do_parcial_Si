# Clínica Santa Cruz — imagen de producción (Laravel 12, PHP 8.2, Apache, PostgreSQL).
#
#   docker build -t clinica-santa-cruz .
#   docker run -p 8080:80 --env-file <archivo con APP_KEY, DB_*…> clinica-santa-cruz
#
# La configuración (APP_KEY, APP_URL, DB_*, etc.) se pasa como variables de entorno del
# servicio; la imagen no contiene .env ni secretos. El contenedor no ejecuta migraciones.

# ---------------------------------------------------------------------------
# 1) Recursos de Vite (public/build). Vite 7 requiere Node 20.19+ o 22.12+.
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# 2) Aplicación: PHP 8.2 + Apache
# ---------------------------------------------------------------------------
FROM php:8.2-apache

# Extensiones: pdo_pgsql para PostgreSQL y opcache. El resto de las que exige composer.lock
# (mbstring, openssl, tokenizer, ctype, dom, fileinfo, …) ya vienen en la imagen oficial.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# php.ini de producción y opcache
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/produccion.ini "$PHP_INI_DIR/conf.d/zz-clinica.ini"

# Apache: DocumentRoot en public/ y .htaccess de Laravel (mod_rewrite)
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias de Composer (capa en caché mientras composer.json/lock no cambien)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# Código de la aplicación (ver .dockerignore) y recursos compilados
COPY . .
COPY --from=assets /app/public/build ./public/build

# Autoload optimizado; ejecuta package:discover sin los paquetes de desarrollo.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/clinica-entrypoint
# Por si el archivo llega con finales de línea de Windows (CRLF).
RUN sed -i 's/\r$//' /usr/local/bin/clinica-entrypoint && chmod +x /usr/local/bin/clinica-entrypoint

# Registros a la salida estándar del contenedor (los ficheros de storage/logs no persisten).
ENV LOG_CHANNEL=stderr

EXPOSE 80

ENTRYPOINT ["clinica-entrypoint"]
CMD ["apache2-foreground"]
