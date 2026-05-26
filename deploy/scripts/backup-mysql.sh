#!/usr/bin/env bash
# Ежедневный дамп MySQL для Accel (chatswitch).
# Cron: deploy/cron/accel-mysql-backup (03:15 Asia/Almaty)

set -euo pipefail

ENV_FILE="${ACCEL_ENV_FILE:-/var/www/accel/shared/.env}"
BACKUP_DIR="${ACCEL_BACKUP_DIR:-/var/www/accel/shared/backups/mysql}"
RETENTION_DAYS="${ACCEL_BACKUP_RETENTION_DAYS:-30}"
LOG_FILE="${ACCEL_BACKUP_LOG:-/var/www/accel/shared/storage/logs/mysql-backup.log}"

log() {
    echo "[$(date -Iseconds)] $*" | tee -a "$LOG_FILE"
}

if [[ ! -r "$ENV_FILE" ]]; then
    log "ERROR: cannot read env file: $ENV_FILE"
    exit 1
fi

# shellcheck disable=SC1090
set -a
source <(grep -E '^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' "$ENV_FILE" | sed 's/\r$//')
set +a

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_DATABASE:?DB_DATABASE missing in $ENV_FILE}"
: "${DB_USERNAME:?DB_USERNAME missing in $ENV_FILE}"
: "${DB_PASSWORD:?DB_PASSWORD missing in $ENV_FILE}"

mkdir -p "$BACKUP_DIR"
chmod 750 "$BACKUP_DIR" 2>/dev/null || true

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_SQL="${BACKUP_DIR}/chatswitch-${STAMP}.sql"
OUT_GZ="${OUT_SQL}.gz"

log "START dump database=${DB_DATABASE} host=${DB_HOST}"

mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --password="$DB_PASSWORD" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" > "$OUT_SQL"

gzip -9 "$OUT_SQL"
chmod 640 "$OUT_GZ"

SIZE="$(du -h "$OUT_GZ" | awk '{print $1}')"
log "OK ${OUT_GZ} (${SIZE})"

DELETED=0
while IFS= read -r -d '' old; do
    rm -f "$old"
    DELETED=$((DELETED + 1))
done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'chatswitch-*.sql.gz' -mtime +"$RETENTION_DAYS" -print0)

if [[ "$DELETED" -gt 0 ]]; then
    log "CLEANUP removed ${DELETED} file(s) older than ${RETENTION_DAYS} days"
fi

log "DONE"
