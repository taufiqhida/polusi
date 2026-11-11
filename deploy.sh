#!/usr/bin/env bash
# Deploy ISPU Indonesia 2026 ke VPS (Ubuntu/Debian). Jalankan dari folder project:
#   sudo bash deploy.sh                       -> git pull kode terbaru + jalankan https://polusi.skaladigital.my.id
#   sudo NO_PULL=1 bash deploy.sh             -> tanpa git pull
#   sudo EMAIL=kamu@mail.com bash deploy.sh   -> sekalian pasang SSL Let's Encrypt (butuh certbot)
#   sudo PORT=8095 bash deploy.sh             -> paksa port container tertentu
#   sudo DOMAIN=lain.com SCHEME=http bash deploy.sh
#
# Jika port 80 sudah dipakai Nginx di VPS, container dijalankan di 127.0.0.1:8095 dan
# vhost Nginx untuk DOMAIN dibuat otomatis (file lain di Nginx tidak disentuh).
set -euo pipefail
cd "$(dirname "$0")"

HOST="${DOMAIN:-polusi.skaladigital.my.id}"
SCHEME="${SCHEME:-https}"
EMAIL="${EMAIL:-}"
CSV="data/indonesia_kualitas_udara_ispu_enriched.csv"

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33m!!  %s\033[0m\n' "$*"; }

# ---- Ambil kode terbaru dari GitHub (konfigurasi server .env tetap aman) ----
if [ -z "${NO_PULL:-}" ] && [ -d .git ]; then
    say "Mengambil kode terbaru (git pull)"
    git config --global --add safe.directory "$PWD" 2>/dev/null || true
    BRANCH="$(git rev-parse --abbrev-ref HEAD)"
    [ -f .env ] && cp .env .env.deploy-backup
    git fetch origin "$BRANCH"
    git reset --hard "origin/$BRANCH"     # kode di server selalu sama dengan GitHub
    [ -f .env.deploy-backup ] && mv -f .env.deploy-backup .env
    git log -1 --format='    versi: %h %s (%cr)'
    exec env NO_PULL=1 bash "$PWD/$(basename "$0")" "$@"     # jalankan ulang deploy.sh versi terbaru
fi

[ -f "$CSV" ] || { echo "CSV tidak ditemukan di $CSV"; exit 1; }

if ! command -v docker >/dev/null 2>&1; then
    say "Memasang Docker"
    curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null

# ---- Tentukan port: 80 kalau kosong, kalau tidak 8095 di belakang reverse proxy ----
port_busy() {  # port dipakai proses lain (bukan container web kita sendiri)?
    local p="$1" ours
    ours="$(docker compose port web 80 2>/dev/null | awk -F: '{print $NF}')"
    [ "$ours" = "$p" ] && return 1
    ss -ltnH "( sport = :$p )" 2>/dev/null | grep -q .
}
if [ -z "${PORT:-}" ]; then
    if port_busy 80; then
        PORT=8095
        while port_busy "$PORT"; do PORT=$((PORT + 1)); done
    else
        PORT=80
    fi
fi
if [ "$PORT" = "80" ]; then BIND="0.0.0.0"; PROXY=""; else BIND="127.0.0.1"; PROXY=1; fi
say "Container web akan memakai $BIND:$PORT"

if [ ! -f .env ]; then
    say "Membuat password database acak"
    DB_PASS="$(openssl rand -hex 16)"
    ROOT_PASS="$(openssl rand -hex 16)"
    printf 'APP_PORT=%s\nAPP_BIND=%s\nDB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\n' "$PORT" "$BIND" "$DB_PASS" "$ROOT_PASS" > .env
    chmod 600 .env
else
    DB_PASS="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"
    sed -i -e '/^APP_PORT=/d' -e '/^APP_BIND=/d' .env
    printf 'APP_PORT=%s\nAPP_BIND=%s\n' "$PORT" "$BIND" >> .env
fi

if [ ! -f src/.env ]; then
    say "Menyiapkan src/.env (production)"
    cp src/.env.example src/.env
    sed -i -e '/^# *DB_/d' -e '/^DB_/d' src/.env
    printf 'DB_CONNECTION=mysql\nDB_HOST=db\nDB_PORT=3306\nDB_DATABASE=ispu\nDB_USERNAME=ispu\nDB_PASSWORD=%s\n' "$DB_PASS" >> src/.env
fi
NEW_KEY=""
grep -q '^APP_KEY=.\+' src/.env || NEW_KEY=1
URL="$SCHEME://$HOST"
sed -i -e 's/^APP_NAME=.*/APP_NAME="ISPU Indonesia 2026"/' -e 's/^APP_ENV=.*/APP_ENV=production/' \
    -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' -e "s#^APP_URL=.*#APP_URL=$URL#" -e 's/^APP_LOCALE=.*/APP_LOCALE=id/' \
    -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' -e 's/^CACHE_STORE=.*/CACHE_STORE=file/' -e 's/^LOG_LEVEL=.*/LOG_LEVEL=warning/' src/.env

say "Build & menjalankan container"
docker compose up -d --build --remove-orphans

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

# ---- Reverse proxy lewat Nginx yang sudah ada di VPS ----
if [ -n "$PROXY" ]; then
    OWNER="$(ss -ltnpH '( sport = :80 )' 2>/dev/null | grep -o 'users:(("[^"]*' | head -1 | cut -d'"' -f2 || true)"
    AAPANEL=""
    NGINX_BIN="$(command -v nginx || true)"
    if [ -d /www/server/panel/vhost/nginx ]; then
        AAPANEL=1   # aaPanel / BT Panel: nginx di /www/server/nginx, vhost di /www/server/panel/vhost/nginx
        [ -x /www/server/nginx/sbin/nginx ] && NGINX_BIN=/www/server/nginx/sbin/nginx
    fi
    if [ -n "$AAPANEL" ]; then
        # Di aaPanel, domain didaftarkan lewat Website > Proxy Project (agar tampil di panel & SSL bisa diurus panel).
        OLD="/www/server/panel/vhost/nginx/$HOST.conf"
        if [ -f "$OLD" ] && grep -q 'ISPU-DEPLOY' "$OLD"; then
            rm -f "$OLD" && "$NGINX_BIN" -t 2>/dev/null && "$NGINX_BIN" -s reload
            say "Vhost lama buatan script dihapus (diganti Proxy Project aaPanel)"
        fi
        say "Terdeteksi aaPanel. Daftarkan domain di panel:"
        echo "    Website > Proxy Project > Add proxy"
        echo "      Domain        : $HOST"
        echo "      Proxy address : http://127.0.0.1:$PORT"
        echo "    Lalu Modify > SSL > Let's Encrypt (seperti website lain di panel)."
    elif [ "$OWNER" = "nginx" ] && [ -n "$NGINX_BIN" ] && [ -d /etc/nginx ]; then
        if [ -d /etc/nginx/sites-available ] && [ -d /etc/nginx/sites-enabled ]; then
            CONF="/etc/nginx/sites-available/$HOST.conf"; LINK="/etc/nginx/sites-enabled/$HOST.conf"
        else
            CONF="/etc/nginx/conf.d/$HOST.conf"; LINK=""
        fi
        if [ -f "$CONF" ] && ! grep -q 'ISPU-DEPLOY' "$CONF"; then
            warn "$CONF sudah ada dan bukan buatan script ini; tidak diubah. Pastikan isinya proxy_pass ke http://127.0.0.1:$PORT"
        else
            say "Memasang vhost Nginx: $CONF"
            BACKUP=""
            [ -f "$CONF" ] && BACKUP="$(cat "$CONF")"
            sed -e "s/__DOMAIN__/$HOST/g" -e "s/__PORT__/$PORT/g" -e "s/__ID__/$(echo "$HOST" | tr -c 'a-zA-Z0-9\n' '_')/g" \
                docker/nginx/host-proxy.conf > "$CONF"
            [ -n "$LINK" ] && ln -sf "$CONF" "$LINK"
            if "$NGINX_BIN" -t 2>/dev/null; then
                "$NGINX_BIN" -s reload
                say "Nginx di-reload"
            else
                warn "nginx -t gagal, vhost dibatalkan:"
                "$NGINX_BIN" -t || true
                if [ -n "$BACKUP" ]; then printf '%s\n' "$BACKUP" > "$CONF"; else rm -f "$CONF"; [ -n "$LINK" ] && rm -f "$LINK"; fi
            fi
        fi

        if [ "$SCHEME" = "https" ]; then
            if [ -n "$EMAIL" ] && command -v certbot >/dev/null 2>&1; then
                say "Memasang SSL Let's Encrypt untuk $HOST"
                certbot --nginx -d "$HOST" --non-interactive --agree-tos -m "$EMAIL" --redirect \
                    || warn "certbot gagal. Pastikan DNS $HOST sudah mengarah ke IP VPS ini."
            else
                warn "SSL belum dipasang. Kalau tidak memakai Cloudflare, jalankan: certbot --nginx -d $HOST"
            fi
        fi
    else
        warn "Port 80 dipakai '${OWNER:-proses lain}', bukan Nginx. Arahkan $HOST secara manual ke http://127.0.0.1:$PORT"
    fi
fi

say "Selesai! Buka: $URL   (cek langsung di VPS: curl -I http://127.0.0.1:$PORT/)"
docker compose ps
