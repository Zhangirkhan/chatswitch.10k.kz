#!/usr/bin/env bash
# Zero-downtime deploy: новый релиз → сборка → переключение симлинка → reload workers.
# Запуск: sudo deploy/cluster/deploy.sh [git-ref]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите от root: sudo ${SCRIPT_DIR}/deploy.sh" >&2
    exit 1
fi

accel_cluster_load_config

GIT_REF="${1:-${GIT_BRANCH}}"
TS="$(date +%Y%m%d_%H%M%S)"
RELEASE="${RELEASES_DIR}/${TS}"
CURRENT="$(accel_cluster_current_release)"

mkdir -p "${RELEASES_DIR}"

accel_cluster_log "Копирование ${CURRENT} → ${RELEASE}…"
rsync -a \
    --exclude='storage' \
    --exclude='.env' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='public/build' \
    --exclude='.phpunit.result.cache' \
    "${CURRENT}/" "${RELEASE}/"

accel_cluster_link_shared "${RELEASE}"

cd "${RELEASE}"
if [[ -d .git ]]; then
    accel_cluster_log "git fetch + checkout ${GIT_REF}…"
    sudo -u www-data git fetch --all --prune
    sudo -u www-data git checkout "${GIT_REF}"
    sudo -u www-data git pull --ff-only origin "${GIT_REF}" 2>/dev/null || sudo -u www-data git pull --ff-only || true
fi

accel_cluster_build_release "${RELEASE}"
accel_cluster_activate_release "${RELEASE}"
accel_cluster_reload_services
sudo -u www-data php "${APP_LINK}/artisan" tenants:sync-nginx-map --reload 2>/dev/null || true
sudo -u www-data php "${APP_LINK}/artisan" platform-changelog:sync-git --no-interaction 2>/dev/null || true
accel_cluster_prune_releases

accel_cluster_log "Деплой завершён. Активный релиз: $(accel_cluster_current_release)"
