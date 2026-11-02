#!/bin/sh
# Entrypoint de la tienda para Docker/Railway
set -e
PORT="${PORT:-8080}"
export PORT
ROOT=/var/www/html

# 1) Apache escucha en $PORT (Railway lo inyecta)
echo "Listen ${PORT}" > /etc/apache2/ports.conf

# 2) Uploads: soporte de Volume (montado en /var/www/html/uploads o en UPLOADS_PATH)
UP="${UPLOADS_PATH:-$ROOT/uploads}"
if [ "$UP" != "$ROOT/uploads" ]; then
  mkdir -p "$UP"
  if [ ! -L "$ROOT/uploads" ]; then rm -rf "$ROOT/uploads"; ln -s "$UP" "$ROOT/uploads"; fi
fi
mkdir -p "$UP/products" "$UP/private/qr"
# Un Volume nuevo llega vacío: restaurar los .htaccess/index de protección
[ -f "$UP/.htaccess" ] || cp /opt/tienda-defaults/uploads/.htaccess "$UP/.htaccess"
[ -f "$UP/private/.htaccess" ] || cp /opt/tienda-defaults/uploads/private/.htaccess "$UP/private/.htaccess"
[ -f "$UP/private/qr/index.html" ] || : > "$UP/private/qr/index.html"
chown -R www-data:www-data "$UP" "$ROOT/config" 2>/dev/null || true
if [ "$UP" != "$ROOT/uploads" ]; then
  # El symlink debe pasar la regla de Apache para /uploads/private
  cat > /etc/apache2/conf-enabled/zz-uploads.conf <<CONF
<Directory "$UP">
    Options -Indexes -ExecCGI +FollowSymLinks
    AllowOverride All
    Require all granted
    php_admin_flag engine off
</Directory>
<Directory "$UP/private">
    Require all denied
</Directory>
CONF
fi

# 3) Migración opcional e idempotente
if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
  php "$ROOT/cron/migrate.php" --wait="${DB_WAIT_SECONDS:-90}" || echo "[entrypoint] AVISO: la migración falló (ver arriba)."
fi

# 4) Admin desde variables (un solo uso; no imprime la contraseña)
if [ -n "${ADMIN_USER:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ] && [ -f "$ROOT/install.php" ]; then
  php "$ROOT/install.php" --from-env || true
fi

# 5) Cron interno opcional para liberar reservas vencidas (cada 15 min)
if [ "${ENABLE_CRON:-0}" = "1" ]; then
  ( while true; do sleep 900; php "$ROOT/cron/expirar_reservas.php" >/dev/null 2>&1 || true; done ) &
fi

exec docker-php-entrypoint "$@"
