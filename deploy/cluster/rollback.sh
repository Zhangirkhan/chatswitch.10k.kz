#!/usr/bin/env bash
# Откат на предыдущий релиз (или указанный каталог).
# Запуск: sudo deploy/cluster/rollback.sh [release_dir]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите от root: sudo ${SCRIPT_DIR}/rollback.sh" >&2
    exit 1
fi

accel_cluster_load_config

ACTIVE="$(accel_cluster_current_release)"

if [[ -n "${1:-}" ]]; then
    TARGET="$(readlink -f "$1")"
else
    mapfile -t releases < <(ls -1dt "${RELEASES_DIR}"/*/ 2>/dev/null | sed 's#/$##' || true)
    TARGET=""
    for dir in "${releases[@]}"; do
        if [[ "${dir}" != "${ACTIVE}" ]]; then
            TARGET="${dir}"
            break
        fi
    done
fi

if [[ -z "${TARGET}" || ! -d "${TARGET}" ]]; then
    echo "Нет релиза для отката." >&2
    exit 1
fi

if [[ "${TARGET}" == "${ACTIVE}" ]]; then
    echo "Уже активен: ${TARGET}" >&2
    exit 0
fi

accel_cluster_log "rollback ${ACTIVE} → ${TARGET}"
accel_cluster_activate_release "${TARGET}"
accel_cluster_reload_services
accel_cluster_log "Откат выполнен."
