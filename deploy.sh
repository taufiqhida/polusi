#!/usr/bin/env bash
# Deploy ISPU Indonesia 2026 ke VPS (Ubuntu/Debian). Jalankan dari folder project:
#   sudo bash deploy.sh              -> web di port 80
#   sudo PORT=8095 bash deploy.sh    -> web di port lain
#   sudo DOMAIN=ispu.contoh.com bash deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

PORT="${PORT:-80}"
HOST="${DOMAIN:-$(curl -fsS https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')}"
CSV="data/indonesia_kualitas_udara_ispu_enriched.csv"

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

[ -f "$CSV" ] || { echo "CSV tidak ditemukan di $CSV"; exit 1; }

if ! command -v docker >/dev/null 2>&1; then
    say "Memasang Docker"
    curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null

if [ ! -f .env ]; then
    say "Membuat password database acak"
    DB_PASS="$(openssl rand -hex 16)"
    ROOT_PASS="$(openssl rand -hex 16)"
    printf 'APP_PORT=%s\nDB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\n' "$PORT" "$DB_PASS" "$ROOT_PASS" > .env
    chmod 600 .env
else
    DB_PASS="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"
    sed -i "s/^APP_PORT=.*/APP_PORT=$PORT/" .env
fi

if [ ! -f src/.env ]; then
    say "Menyiapkan src/.env (production)"
    cp src/.env.example src/.env
    sed -i -e '/^# *DB_/d' -e '/^DB_/d' src/.env
    printf 'DB_CONNECTION=mysql\nDB_HOST=db\nDB_PORT=3306\nDB_DATABASE=ispu\nDB_USERNAME=ispu\nDB_PASSWORD=%s\n' "$DB_PASS" >> src/.env
fi
NEW_KEY=""
grep -q '^APP_KEY=.\+' src/.env || NEW_KEY=1
URL="http://$HOST"; [ "$PORT" = "80" ] || URL="$URL:$PORT"
sed -i -e 's/^APP_NAME=.*/APP_NAME="ISPU Indonesia 2026"/' -e 's/^APP_ENV=.*/APP_ENV=production/' \
    -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' -e "s#^APP_URL=.*#APP_URL=$URL#" -e 's/^APP_LOCALE=.*/APP_LOCALE=id/' \
    -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' -e 's/^CACHE_STORE=.*/CACHE_STORE=file/' -e 's/^LOG_LEVEL=.*/LOG_LEVEL=warning/' src/.env

say "Build & menjalankan container"
docker compose up -d --build

say "Menunggu MySQL siap"
for i in $(seq 1 60); do
    docker compose exec -T db mysqladmin ping -h127.0.0.1 --silent >/dev/null 2>&1 && break
    sleep 3
done

say "Install dependency, migrasi, import data, evaluasi model"
docker compose exec -T app sh -c "
    composer install --no-dev --optimize-autoloader --no-interaction &&
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache &&
    chown -R www-data:www-data storage bootstrap/cache &&
    ${NEW_KEY:+php artisan key:generate --force &&}
    php artisan migrate --force &&
    php artisan ispu:import &&
    php artisan cache:clear &&
    php artisan ispu:evaluate &&
    chown -R www-data:www-data storage &&
    php artisan optimize
"

say "Selesai! Buka: $URL"
docker compose ps
