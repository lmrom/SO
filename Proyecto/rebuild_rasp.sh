#!/usr/bin/env bash
set -euo pipefail

# Rebuild completo de Raspberry para proyecto GATE AUDIT.
# Ejecutar desde la laptop/host local donde vive este repo.

PI_HOST="${PI_HOST:-10.63.184.78}"
PI_USER="${PI_USER:-lumr}"
PI_SUDO_PASS="${PI_SUDO_PASS:-lumr2018}"
PI_PORT="${PI_PORT:-22}"

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="${PROJECT_DIR}/api"
SQL_DIR="${PROJECT_DIR}/sqlite_db"

INDEX_FILE="${API_DIR}/index.php"
ACCESO_FILE="${API_DIR}/acceso.php"
INIT_SQL="${SQL_DIR}/init.sql"
SEED_SQL="${SQL_DIR}/seed.sql"

REMOTE_TMP_DIR="/home/${PI_USER}/gate_rebuild_tmp"
REMOTE_WEB_ROOT="/var/www/html"
REMOTE_DB="${REMOTE_WEB_ROOT}/audit.db"

SSH_OPTS=(
  -p "${PI_PORT}"
  -o StrictHostKeyChecking=accept-new
  -o ConnectTimeout=8
)

require_file() {
  local f="$1"
  if [[ ! -f "$f" ]]; then
    echo "ERROR: no existe archivo requerido: $f" >&2
    exit 1
  fi
}

run_ssh() {
  ssh "${SSH_OPTS[@]}" "${PI_USER}@${PI_HOST}" "$@"
}

run_sudo_ssh() {
  local cmd="$1"
  run_ssh "printf '%s\n' '${PI_SUDO_PASS}' | sudo -S bash -lc $(printf '%q' "$cmd")"
}

local_ip_guess() {
  ip -4 route get "${PI_HOST}" 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}'
}

main() {
  require_file "${INDEX_FILE}"
  require_file "${ACCESO_FILE}"
  require_file "${INIT_SQL}"
  require_file "${SEED_SQL}"

  local local_ip
  local_ip="${FACE_VERIFY_IP:-$(local_ip_guess || true)}"
  if [[ -z "${local_ip}" ]]; then
    local_ip="10.63.184.19"
  fi

  echo "===> Target PI: ${PI_USER}@${PI_HOST}:${PI_PORT}"
  echo "===> Local Face Verify IP: ${local_ip}"

  echo "===> Probando SSH"
  run_ssh "echo connected && hostname && uname -a"

  echo "===> Creando dir temporal remoto"
  run_ssh "mkdir -p '${REMOTE_TMP_DIR}'"

  echo "===> Copiando archivos del proyecto"
  scp "${SSH_OPTS[@]}" \
    "${INDEX_FILE}" \
    "${ACCESO_FILE}" \
    "${INIT_SQL}" \
    "${SEED_SQL}" \
    "${PI_USER}@${PI_HOST}:${REMOTE_TMP_DIR}/"

  echo "===> Actualizando FACE_VERIFY_REMOTE_URL en acceso.php remoto"
  run_ssh "sed -i \"s#^const FACE_VERIFY_REMOTE_URL = 'http://[^']*';#const FACE_VERIFY_REMOTE_URL = 'http://${local_ip}:5050/verify';#\" '${REMOTE_TMP_DIR}/acceso.php'"

  echo "===> Instalando paquetes base"
  run_sudo_ssh "apt-get clean && rm -rf /var/lib/apt/lists/* && apt-get update -o Acquire::Retries=5"
  run_sudo_ssh "DEBIAN_FRONTEND=noninteractive apt-get install -y nginx php-fpm php-cli php-sqlite3 php-curl php-mbstring php-xml sqlite3 phpliteadmin"

  echo "===> Desplegando web root y API"
  run_sudo_ssh "mkdir -p '${REMOTE_WEB_ROOT}' '${REMOTE_WEB_ROOT}/fotos' '${REMOTE_WEB_ROOT}/fotos/revision' '${REMOTE_WEB_ROOT}/fotos/autorizado' '${REMOTE_WEB_ROOT}/fotos/denegado' '${REMOTE_WEB_ROOT}/fotos/db'"
  run_sudo_ssh "cp '${REMOTE_TMP_DIR}/index.php' '${REMOTE_WEB_ROOT}/index.php'"
  run_sudo_ssh "cp '${REMOTE_TMP_DIR}/acceso.php' '${REMOTE_WEB_ROOT}/acceso.php'"

  echo "===> Recreando base SQLite con seed"
  run_sudo_ssh "rm -f '${REMOTE_DB}'"
  run_sudo_ssh "sqlite3 '${REMOTE_DB}' < '${REMOTE_TMP_DIR}/init.sql'"
  run_sudo_ssh "sqlite3 '${REMOTE_DB}' < '${REMOTE_TMP_DIR}/seed.sql'"

  echo "===> Configurando revision mode"
  run_sudo_ssh "cat > '${REMOTE_WEB_ROOT}/revision_mode.json' <<'JSON'
{
  \"mode\": \"MANUAL\",
  \"updated_at\": \"$(date -Iseconds)\"
}
JSON"

  echo "===> Configurando phpLiteAdmin"
  run_sudo_ssh "ln -sf /usr/share/phpliteadmin/phpliteadmin.php '${REMOTE_WEB_ROOT}/phpliteadmin.php'"
  run_sudo_ssh "mkdir -p /var/lib/phpliteadmin && ln -sf '${REMOTE_DB}' /var/lib/phpliteadmin/audit.db"
  run_sudo_ssh "sed -i \"s#^//\\\$password = .*#\\\$password = '${PI_SUDO_PASS}';#\" /etc/phpliteadmin.config.php"

  echo "===> Configurando nginx default para PHP-FPM"
  run_sudo_ssh "cat > /etc/nginx/sites-available/default <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /var/www/html;
    index index.php index.html index.htm;
    server_name _;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\\.ht {
        deny all;
    }
}
NGINX"

  echo "===> Permisos y archivo log"
  run_sudo_ssh "touch '${REMOTE_WEB_ROOT}/log.txt'"
  run_sudo_ssh "chown -R '${PI_USER}:www-data' '${REMOTE_WEB_ROOT}/fotos' '${REMOTE_WEB_ROOT}/log.txt'"
  run_sudo_ssh "chown www-data:www-data '${REMOTE_WEB_ROOT}/index.php' '${REMOTE_WEB_ROOT}/acceso.php' '${REMOTE_DB}' '${REMOTE_WEB_ROOT}/revision_mode.json'"
  run_sudo_ssh "chmod 2775 '${REMOTE_WEB_ROOT}/fotos' '${REMOTE_WEB_ROOT}/fotos/revision' '${REMOTE_WEB_ROOT}/fotos/autorizado' '${REMOTE_WEB_ROOT}/fotos/denegado' '${REMOTE_WEB_ROOT}/fotos/db'"
  run_sudo_ssh "chmod 775 '${REMOTE_WEB_ROOT}'"
  run_sudo_ssh "chmod 664 '${REMOTE_WEB_ROOT}/log.txt'"
  run_sudo_ssh "usermod -aG www-data '${PI_USER}' || true"

  echo "===> Habilitando y reiniciando servicios"
  run_sudo_ssh "nginx -t"
  run_sudo_ssh "systemctl enable nginx php8.4-fpm"
  run_sudo_ssh "systemctl restart php8.4-fpm nginx"

  echo "===> Health checks"
  run_ssh "systemctl is-active nginx && systemctl is-active php8.4-fpm"
  run_ssh "php -m | egrep 'sqlite3|pdo_sqlite|curl|mbstring|xml'"
  run_ssh "curl -sS -o /tmp/rebuild_index.html -w 'index_http=%{http_code}\n' http://localhost/"
  run_ssh "curl -sS -o /tmp/rebuild_phplite.html -w 'phplite_http=%{http_code}\n' http://localhost/phpliteadmin.php"
  run_ssh "curl -sS -X POST http://localhost/acceso.php -d 'accion=OBTENER_MODO_REVISION'"
  run_ssh "ls -ld '${REMOTE_WEB_ROOT}/fotos' '${REMOTE_WEB_ROOT}/fotos/revision' '${REMOTE_WEB_ROOT}/fotos/autorizado' '${REMOTE_WEB_ROOT}/fotos/denegado' '${REMOTE_WEB_ROOT}/fotos/db'"

  echo "===> Limpieza"
  run_ssh "rm -rf '${REMOTE_TMP_DIR}'"

  cat <<EOF
Rebuild completado.
Abrir:
- http://${PI_HOST}/
- http://${PI_HOST}/phpliteadmin.php
EOF
}

main "$@"
