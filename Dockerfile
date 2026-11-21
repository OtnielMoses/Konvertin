# syntax=docker/dockerfile:1.7
#
# Image produksi Konvertin: PHP 8.5 + Apache, mesin Python (PyMuPDF, OCR lokal),
# dan Ghostscript untuk PDF/A. Satu kontainer, tanpa database eksternal.
#
#   docker build -t konvertin .
#   docker run -p 8080:8080 -e APP_KEY=base64:... -e APP_URL=https://domain-anda konvertin
#
# Opsional saat build:
#   --build-arg WITH_CHROMIUM=1     HTML to PDF (Chromium headless, +~300 MB)
#   --build-arg WITH_LIBREOFFICE=1  Word/PowerPoint/Excel to PDF (+~600 MB);
#                                   alternatif lebih ringan: KONVERTIN_GOTENBERG_URL

# ---------------------------------------------------------------- aset frontend
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------- aplikasi
FROM php:8.5-apache AS app

ARG WITH_CHROMIUM=0
ARG WITH_LIBREOFFICE=0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        python3 python3-venv \
        ghostscript \
        libgl1 libglib2.0-0 \
        fonts-dejavu-core fonts-liberation \
        libzip-dev unzip; \
    if [ "$WITH_CHROMIUM" = "1" ]; then apt-get install -y --no-install-recommends chromium; fi; \
    if [ "$WITH_LIBREOFFICE" = "1" ]; then apt-get install -y --no-install-recommends libreoffice-writer libreoffice-calc libreoffice-impress; fi; \
    docker-php-ext-install zip; \
    a2enmod rewrite headers; \
    rm -rf /var/lib/apt/lists/*

# Mesin Python di virtualenv terpisah dari Python sistem.
COPY resources/engines/requirements.txt /tmp/requirements.txt
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt \
    && rm /tmp/requirements.txt

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Batas unggah mengikuti config/konvertin.php: 50 MB per berkas, 30 berkas per proses.
COPY <<'EOF' /usr/local/etc/php/conf.d/konvertin.ini
upload_max_filesize = 50M
post_max_size = 1600M
max_file_uploads = 35
max_execution_time = 300
memory_limit = 512M
expose_php = Off
opcache.enable = 1
opcache.validate_timestamps = 0
EOF

# Document root ke public/, dan port mengikuti variabel PORT (Render, Railway, Fly.io).
COPY <<'EOF' /etc/apache2/sites-available/000-default.conf
<VirtualHost *:${PORT}>
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
    </Directory>
    LimitRequestBody 1677721600
    ServerSignature Off
    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
EOF

COPY <<'EOF' /usr/local/bin/konvertin-entrypoint
#!/bin/sh
set -e
cd /var/www/html

mkdir -p storage/app/konvertin storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
touch database/database.sqlite

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force --graceful

chown -R www-data:www-data storage bootstrap/cache database

# Penjadwal: menghapus berkas sementara yang kedaluwarsa setiap 5 menit (konvertin:purge).
su -s /bin/sh www-data -c "php artisan schedule:work" &

exec apache2-foreground
EOF

RUN chmod +x /usr/local/bin/konvertin-entrypoint \
    && echo 'Listen ${PORT}' > /etc/apache2/ports.conf \
    && echo 'ServerTokens Prod' >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -f .env

ENV PORT=8080 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    KONVERTIN_PYTHON=/opt/venv/bin/python \
    KONVERTIN_TRUSTED_PROXIES=* \
    HOME=/tmp

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s \
    CMD php -r '$c = stream_context_create(["http" => ["header" => "X-Forwarded-Proto: https", "follow_location" => 0]]); exit(@file_get_contents("http://127.0.0.1:".getenv("PORT")."/up", false, $c) === false ? 1 : 0);'

ENTRYPOINT ["konvertin-entrypoint"]
