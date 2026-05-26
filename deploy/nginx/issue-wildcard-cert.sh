#!/usr/bin/env bash
# Wildcard-сертификат *.accel.kz (DNS-01). Нужен TXT у регистратора DNS.
# После выпуска: обновите ssl_certificate в блоке *.accel.kz (deploy/nginx/install-wildcard-ssl.sh).
set -euo pipefail

ROOT_DOMAIN="${ROOT_DOMAIN:-accel.kz}"
CERT_NAME="${CERT_NAME:-accel.kz-wildcard}"
ACME_EMAIL="${ACME_EMAIL:-super@accel.kz}"

log() { printf '[issue-wildcard-cert] %s\n' "$*" >&2; }

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите от root: sudo $0" >&2
    exit 1
fi

log "Будет запрошен TXT _acme-challenge.${ROOT_DOMAIN} у вашего DNS-провайдера."
log "Следуйте подсказкам certbot, затем выполните: sudo deploy/nginx/install-wildcard-ssl.sh"

certbot certonly \
    --manual \
    --preferred-challenges=dns \
    --cert-name "${CERT_NAME}" \
    -d "${ROOT_DOMAIN}" \
    -d "*.${ROOT_DOMAIN}" \
    --agree-tos \
    --no-eff-email \
    -m "${ACME_EMAIL}"

log "Сертификат: /etc/letsencrypt/live/${CERT_NAME}/"
log "Далее: sudo $(dirname "$0")/install-wildcard-ssl.sh"
