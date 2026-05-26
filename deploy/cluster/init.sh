#!/usr/bin/env bash
# Однократная миграция /var/www/accel.kz → release-кластер (/var/www/accel).
# Запуск: sudo deploy/cluster/init.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите от root: sudo ${SCRIPT_DIR}/init.sh" >&2
    exit 1
fi

accel_cluster_load_config

if [[ -L "${APP_LINK}" ]]; then
    accel_cluster_log "Уже в release-режиме: ${APP_LINK} → $(readlink -f "${APP_LINK}")"
    exit 0
fi

if [[ ! -d "${APP_LINK}" ]]; then
    echo "Каталог приложения не найден: ${APP_LINK}" >&2
    exit 1
fi

TS="$(date +%Y%m%d_%H%M%S)_initial"
RELEASE="${RELEASES_DIR}/${TS}"

mkdir -p "${SHARED_DIR}" "${RELEASES_DIR}"
mkdir -p "${SHARED_DIR}/bootstrap/cache"

accel_cluster_log "Перенос storage и .env в shared…"
if [[ -d "${APP_LINK}/storage" && ! -L "${APP_LINK}/storage" ]]; then
    if [[ ! -d "${SHARED_DIR}/storage/app" ]]; then
        mv "${APP_LINK}/storage" "${SHARED_DIR}/storage"
    else
        rsync -a "${APP_LINK}/storage/" "${SHARED_DIR}/storage/"
        rm -rf "${APP_LINK}/storage"
    fi
fi
if [[ -f "${APP_LINK}/.env" && ! -L "${APP_LINK}/.env" ]]; then
    mv "${APP_LINK}/.env" "${SHARED_DIR}/.env"
fi
chown -R www-data:www-data "${SHARED_DIR}/storage" "${SHARED_DIR}/bootstrap/cache"
chmod -R ug+rwx "${SHARED_DIR}/storage" "${SHARED_DIR}/bootstrap/cache"

accel_cluster_log "Перенос кода в ${RELEASE}…"
mv "${APP_LINK}" "${RELEASE}"

accel_cluster_link_shared "${RELEASE}"
ln -sfn "${RELEASE}" "${APP_LINK}"

mkdir -p "${ACCEL_BASE}/deploy"
if [[ ! -f "${ACCEL_BASE}/deploy/config.env" ]]; then
    cp "${SCRIPT_DIR}/config.env.example" "${ACCEL_BASE}/deploy/config.env"
fi

accel_cluster_log "Готово. APP_LINK=${APP_LINK} → ${RELEASE}"
accel_cluster_log "Деплой: sudo ${SCRIPT_DIR}/deploy.sh"
