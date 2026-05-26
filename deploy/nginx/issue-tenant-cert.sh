#!/usr/bin/env bash
# Выпускает SSL-сертификат и подключает nginx-блок для нового тенанта.
# Использование: sudo /var/www/accel.kz/deploy/nginx/issue-tenant-cert.sh <slug>
#
# Идемпотентен:
#  - cert переиздаётся только если его нет / просрочен (certbot сам решает);
#  - nginx-блок не дублируется (перезаписывает символическую ссылку).

set -euo pipefail

SLUG="${1:-}"
ROOT_DOMAIN="${ROOT_DOMAIN:-accel.kz}"
WEBROOT="${WEBROOT:-/var/www/accel.kz/public}"
TEMPLATE="${TEMPLATE:-/var/www/accel.kz/deploy/nginx/templates/tenant.conf.template}"
NGINX_AVAILABLE="${NGINX_AVAILABLE:-/etc/nginx/sites-available}"
NGINX_ENABLED="${NGINX_ENABLED:-/etc/nginx/sites-enabled}"
ACME_EMAIL="${ACME_EMAIL:-super@accel.kz}"

log() { printf '[issue-tenant-cert] %s\n' "$*" >&2; }
err() { printf '[issue-tenant-cert][ERROR] %s\n' "$*" >&2; exit 1; }

if [[ -z "$SLUG" ]]; then
    err "Не передан slug. Использование: $0 <slug>"
fi

if ! [[ "$SLUG" =~ ^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$ ]]; then
    err "Недопустимый slug: '$SLUG' (a-z0-9, dash, 1-32 символа)"
fi

# Запрещённые поддомены (которые не являются тенантами)
for r in app www api admin mail static cdn ftp staging test dev ns1 ns2; do
    if [[ "$SLUG" == "$r" ]]; then
        err "Slug '$SLUG' зарезервирован, пропускаю"
    fi
done

FQDN="${SLUG}.${ROOT_DOMAIN}"
CONF_NAME="tenant-${SLUG}.${ROOT_DOMAIN}"
CONF_PATH="${NGINX_AVAILABLE}/${CONF_NAME}"
LINK_PATH="${NGINX_ENABLED}/${CONF_NAME}"

log "FQDN: $FQDN"

if ! getent hosts "$FQDN" >/dev/null 2>&1; then
    log "DNS для $FQDN ещё не резолвится — но wildcard A-запись *.$ROOT_DOMAIN должна работать. Продолжаю."
fi

log "Шаг 1/3: certbot certonly --webroot"
certbot certonly \
    --webroot -w "$WEBROOT" \
    -d "$FQDN" \
    --non-interactive --agree-tos --no-eff-email \
    -m "$ACME_EMAIL" \
    --keep-until-expiring

if [[ ! -f "/etc/letsencrypt/live/${FQDN}/fullchain.pem" ]]; then
    err "Сертификат для $FQDN не создан"
fi

log "Шаг 2/3: nginx server-блок"
TMP="$(mktemp)"
sed "s|__FQDN__|${FQDN}|g" "$TEMPLATE" > "$TMP"
install -m 0644 -o root -g root "$TMP" "$CONF_PATH"
rm -f "$TMP"
ln -sfn "$CONF_PATH" "$LINK_PATH"

log "Шаг 3/3: nginx -t && reload"
nginx -t
systemctl reload nginx

log "OK. https://${FQDN}/ готов."
