#!/usr/bin/env bash
# Установка nginx-конфигов для accel.kz (multi-tenant).
# Запускать от root.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY="${REPO_DIR}/deploy/nginx"

echo "Repo: ${REPO_DIR}"

install -d /etc/nginx/snippets
install -m 0644 "${DEPLOY}/snippets/accel-app.conf" /etc/nginx/snippets/accel-app.conf

install -m 0644 "${DEPLOY}/accel.kz.conf" /etc/nginx/sites-available/accel.kz
ln -sfn /etc/nginx/sites-available/accel.kz /etc/nginx/sites-enabled/accel.kz

nginx -t
systemctl reload nginx
echo "Done. Reloaded nginx."
