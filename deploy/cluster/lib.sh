#!/usr/bin/env bash
# Общие функции release-деплоя Accel.
set -euo pipefail

accel_cluster_load_config() {
    local config="${ACCEL_CLUSTER_CONFIG:-/var/www/accel/deploy/config.env}"
    if [[ -f "${config}" ]]; then
        # shellcheck disable=SC1090
        source "${config}"
    fi
    ACCEL_BASE="${ACCEL_BASE:-/var/www/accel}"
    APP_LINK="${APP_LINK:-/var/www/accel.kz}"
    GIT_BRANCH="${GIT_BRANCH:-main}"
    KEEP_RELEASES="${KEEP_RELEASES:-5}"
    RESTART_WHATSAPP="${RESTART_WHATSAPP:-0}"
    PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
    SUPERVISOR_PROGRAMS="${SUPERVISOR_PROGRAMS:-accel-queue-default:* accel-reverb}"
    SHARED_DIR="${ACCEL_BASE}/shared"
    RELEASES_DIR="${ACCEL_BASE}/releases"
}

accel_cluster_log() {
    echo "[accel-deploy] $*"
}

accel_cluster_current_release() {
    if [[ -L "${APP_LINK}" ]]; then
        readlink -f "${APP_LINK}"
        return 0
    fi
    if [[ -d "${APP_LINK}" ]]; then
        echo "${APP_LINK}"
        return 0
    fi
    echo "APP_LINK not found: ${APP_LINK}" >&2
    return 1
}

accel_cluster_link_shared() {
    local release_dir="$1"
    cd "${release_dir}"

    rm -f .env storage bootstrap/cache 2>/dev/null || true
    rm -rf storage bootstrap/cache 2>/dev/null || true

    ln -sfn "${SHARED_DIR}/.env" .env
    ln -sfn "${SHARED_DIR}/storage" storage
    mkdir -p "${SHARED_DIR}/bootstrap/cache"
    chown -R www-data:www-data "${SHARED_DIR}/storage" "${SHARED_DIR}/bootstrap/cache" 2>/dev/null || true
    chmod -R ug+rwx "${SHARED_DIR}/storage" "${SHARED_DIR}/bootstrap/cache" 2>/dev/null || true
    ln -sfn "${SHARED_DIR}/bootstrap/cache" bootstrap/cache
}

accel_cluster_build_release() {
    local release_dir="$1"
    cd "${release_dir}"

    accel_cluster_log "composer install (production)…"
    sudo -u www-data composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

    if [[ -f package-lock.json ]]; then
        accel_cluster_log "npm ci + build…"
        npm ci --no-audit --no-fund
    else
        accel_cluster_log "npm install + build…"
        npm install --no-audit --no-fund
    fi
    npm run build

    accel_cluster_log "artisan optimize + migrate…"
    sudo -u www-data php artisan migrate --force
    sudo -u www-data php artisan optimize

    chown -R www-data:www-data "${release_dir}/vendor" "${release_dir}/node_modules" "${release_dir}/bootstrap/cache" 2>/dev/null || true
    if [[ -d "${release_dir}/public/build" ]]; then
        chown -R www-data:www-data "${release_dir}/public/build"
    fi
}

accel_cluster_activate_release() {
    local release_dir="$1"
    ln -sfn "${release_dir}" "${APP_LINK}"
    accel_cluster_log "active → ${release_dir}"
}

accel_cluster_reload_services() {
    if systemctl is-active --quiet "${PHP_FPM_SERVICE}" 2>/dev/null; then
        accel_cluster_log "reload ${PHP_FPM_SERVICE}…"
        systemctl reload "${PHP_FPM_SERVICE}"
    fi

    if command -v supervisorctl >/dev/null 2>&1; then
        # shellcheck disable=SC2086
        for prog in ${SUPERVISOR_PROGRAMS}; do
            accel_cluster_log "supervisor restart ${prog}…"
            supervisorctl restart "${prog}" || true
        done
    fi

    if [[ "${RESTART_WHATSAPP}" == "1" ]] && command -v systemctl >/dev/null 2>&1; then
        if systemctl list-units --type=service --all 2>/dev/null | grep -q accel-whatsapp; then
            systemctl restart accel-whatsapp || true
        fi
    fi

    nginx -t 2>/dev/null && systemctl reload nginx || true
}

accel_cluster_prune_releases() {
    local keep="${KEEP_RELEASES}"
    mapfile -t all < <(ls -1dt "${RELEASES_DIR}"/*/ 2>/dev/null | sed 's#/$##' || true)
    local active
    active="$(accel_cluster_current_release)"

    local count=0
    for dir in "${all[@]}"; do
        [[ "${dir}" == "${active}" ]] && continue
        count=$((count + 1))
        if (( count > keep )); then
            accel_cluster_log "remove old release ${dir}"
            rm -rf "${dir}"
        fi
    done
}
