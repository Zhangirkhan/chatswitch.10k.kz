#!/usr/bin/env bash
# Установка выделенного PHP-FPM pool для Accel + обновление nginx upstream.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите от root: sudo ${REPO_DIR}/deploy/php-fpm/install-pool.sh" >&2
    exit 1
fi

POOL_DST="/etc/php/8.3/fpm/pool.d/accel.conf"
install -m 0644 "${REPO_DIR}/deploy/php-fpm/accel.conf" "${POOL_DST}"

# upstream в repo-конфиге nginx
NGINX_REPO="${REPO_DIR}/deploy/nginx/accel.kz.conf"
if grep -q 'php8.3-fpm.sock' "${NGINX_REPO}" 2>/dev/null; then
    sed -i 's|unix:/run/php/php8.3-fpm.sock|unix:/run/php/php8.3-fpm-accel.sock|' "${NGINX_REPO}"
fi

NGINX_LIVE="/etc/nginx/sites-available/accel.kz"
if [[ -f "${NGINX_LIVE}" ]]; then
    sed -i 's|unix:/run/php/php8.3-fpm.sock|unix:/run/php/php8.3-fpm-accel.sock|' "${NGINX_LIVE}"
fi

php-fpm8.3 -t
systemctl restart php8.3-fpm
nginx -t
systemctl reload nginx

echo "PHP-FPM pool [accel] установлен: /run/php/php8.3-fpm-accel.sock"
