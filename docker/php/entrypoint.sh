#!/bin/sh
set -e

if [ ! -f /var/www/html/.env ]; then
    echo "=> [Docker Bootstrap] Criando arquivo .env a partir de .env.example..."
    cp /var/www/html/.env.example /var/www/html/.env
fi

if [ ! -d /var/www/html/vendor ]; then
    echo "=> [Docker Bootstrap] Instalando dependencias do Composer..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if ! grep -q "^APP_KEY=base64:" /var/www/html/.env 2>/dev/null; then
    echo "=> [Docker Bootstrap] Gerando APP_KEY do Laravel..."
    php artisan key:generate --force
fi

mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "=> [Docker Bootstrap] Executando migrations e seeders no banco..."
php artisan migrate --seed --force

echo "=> [Docker Bootstrap] Inicializacao concluida com sucesso!"

exec "$@"

