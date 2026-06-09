#!/usr/bin/env bash
# Восстановление операционных данных одного тенанта из почасового mysqldump.
# Использование: restore-tenant-from-backup.sh <company_id> <backup.sql.gz>

set -euo pipefail

COMPANY_ID="${1:?company_id required}"
BACKUP_GZ="${2:?backup.sql.gz required}"
ENV_FILE="${ACCEL_ENV_FILE:-/var/www/accel/shared/.env}"
TMP_DB="chatswitch_restore_tmp_$$"

log() { echo "[$(date -Iseconds)] $*"; }

if [[ ! -r "$BACKUP_GZ" ]]; then
    log "ERROR: backup not found: $BACKUP_GZ"
    exit 1
fi

# shellcheck disable=SC1090
set -a
source <(grep -E '^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' "$ENV_FILE" | sed 's/\r$//')
set +a

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_DATABASE:?}"
: "${DB_USERNAME:?}"
: "${DB_PASSWORD:?}"

MYSQL=(sudo mysql)

cleanup() {
    log "DROP DATABASE IF EXISTS ${TMP_DB}"
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${TMP_DB}\`" 2>/dev/null || true
}
trap cleanup EXIT

log "Import backup into ${TMP_DB} ..."
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${TMP_DB}\`; CREATE DATABASE \`${TMP_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
zcat "$BACKUP_GZ" | "${MYSQL[@]}" "$TMP_DB"

log "Align temp schema with production (columns added after backup) ..."
# Доп. колонки в temp не обязательны: copy_table_aligned копирует только пересечение.

log "Clear current operational data for company_id=${COMPANY_ID} ..."
"${MYSQL[@]}" "$DB_DATABASE" <<SQL
SET FOREIGN_KEY_CHECKS=0;

DELETE a FROM ai_orchestrator_actions a
INNER JOIN ai_orchestrator_runs r ON r.id = a.ai_orchestrator_run_id
INNER JOIN chats c ON c.id = r.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE r FROM ai_orchestrator_runs r
INNER JOIN chats c ON c.id = r.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE l FROM ai_response_logs l
INNER JOIN chats c ON c.id = l.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE p FROM ai_follow_up_proposals p
INNER JOIN chats c ON c.id = p.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE sm FROM scheduled_messages sm
INNER JOIN chats c ON c.id = sm.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE mr FROM message_reactions mr
INNER JOIN messages m ON m.id = mr.message_id
INNER JOIN chats c ON c.id = m.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE mt FROM message_transcripts mt
INNER JOIN messages m ON m.id = mt.message_id
INNER JOIN chats c ON c.id = m.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE mm FROM message_media mm
INNER JOIN messages m ON m.id = mm.message_id
INNER JOIN chats c ON c.id = m.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE m FROM messages m
INNER JOIN chats c ON c.id = m.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE ca FROM chat_assignments ca
INNER JOIN chats c ON c.id = ca.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE cd FROM chat_department cd
INNER JOIN chats c ON c.id = cd.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE FROM chat_funnel_transitions WHERE company_id = ${COMPANY_ID};

DELETE FROM chats WHERE company_id = ${COMPANY_ID};

DELETE FROM contacts WHERE company_id = ${COMPANY_ID};
DELETE FROM company_contact WHERE company_id = ${COMPANY_ID};

DELETE fsr FROM funnel_stage_ai_rules fsr
INNER JOIN funnels f ON f.id = fsr.funnel_id
WHERE f.company_id = ${COMPANY_ID};

DELETE fs FROM funnel_stages fs
INNER JOIN funnels f ON f.id = fs.funnel_id
WHERE f.company_id = ${COMPANY_ID};

DELETE fas FROM funnel_ai_scenarios fas
INNER JOIN funnels f ON f.id = fas.funnel_id
WHERE f.company_id = ${COMPANY_ID};

DELETE FROM funnels WHERE company_id = ${COMPANY_ID};

DELETE FROM knowledge_audit_logs WHERE company_id = ${COMPANY_ID};
DELETE FROM knowledge_chunks WHERE company_id = ${COMPANY_ID};
DELETE FROM knowledge_rules WHERE company_id = ${COMPANY_ID};

DELETE FROM products WHERE company_id = ${COMPANY_ID};
DELETE FROM services WHERE company_id = ${COMPANY_ID};

DELETE dfs FROM department_funnel_stage dfs
INNER JOIN departments d ON d.id = dfs.department_id
WHERE d.company_id = ${COMPANY_ID};

DELETE df FROM department_funnel df
INNER JOIN departments d ON d.id = df.department_id
WHERE d.company_id = ${COMPANY_ID};

DELETE du FROM department_user du
INNER JOIN departments d ON d.id = du.department_id
WHERE d.company_id = ${COMPANY_ID};

DELETE dp FROM department_posts dp
INNER JOIN departments d ON d.id = dp.department_id
WHERE d.company_id = ${COMPANY_ID};
DELETE FROM departments WHERE company_id = ${COMPANY_ID};

DELETE uws FROM user_whatsapp_session uws
INNER JOIN whatsapp_sessions ws ON ws.id = uws.whatsapp_session_id
WHERE ws.company_id = ${COMPANY_ID};

DELETE FROM whatsapp_sessions WHERE company_id = ${COMPANY_ID};

DELETE ce FROM calendar_events ce
INNER JOIN chats c ON c.id = ce.chat_id
WHERE c.company_id = ${COMPANY_ID};

DELETE FROM entity_memories WHERE tenant_company_id = ${COMPANY_ID};
DELETE FROM company_tone_profiles WHERE company_id = ${COMPANY_ID};
DELETE FROM contact_field_definitions WHERE company_id = ${COMPANY_ID};

SET FOREIGN_KEY_CHECKS=1;
SQL

log "Copy restored rows ..."

copy_table() {
    local table="$1"
    local where="$2"
    log "COPY ${table} ..."
    local cols
    cols="$("${MYSQL[@]}" -N "$DB_DATABASE" -e "
        SELECT GROUP_CONCAT(CONCAT('\`', c.COLUMN_NAME, '\`') ORDER BY c.ORDINAL_POSITION SEPARATOR ', ')
        FROM information_schema.COLUMNS c
        INNER JOIN information_schema.COLUMNS t
            ON t.TABLE_SCHEMA = '${TMP_DB}'
            AND t.TABLE_NAME = c.TABLE_NAME
            AND t.COLUMN_NAME = c.COLUMN_NAME
        WHERE c.TABLE_SCHEMA = '${DB_DATABASE}'
          AND c.TABLE_NAME = '${table}';
    ")"
    if [[ -z "${cols}" ]]; then
        log "SKIP ${table} (no shared columns)"
        return 0
    fi
    "${MYSQL[@]}" "$DB_DATABASE" -e "
        SET FOREIGN_KEY_CHECKS=0;
        INSERT INTO \`${table}\` (${cols})
        SELECT ${cols} FROM \`${TMP_DB}\`.\`${table}\` WHERE ${where};
    "
}

copy_table funnels "company_id = ${COMPANY_ID}"
copy_table funnel_stages "funnel_id IN (SELECT id FROM \`${TMP_DB}\`.funnels WHERE company_id = ${COMPANY_ID})"
copy_table departments "company_id = ${COMPANY_ID}"
copy_table department_user "department_id IN (SELECT id FROM \`${TMP_DB}\`.departments WHERE company_id = ${COMPANY_ID})"
copy_table department_funnel "department_id IN (SELECT id FROM \`${TMP_DB}\`.departments WHERE company_id = ${COMPANY_ID})"
copy_table department_funnel_stage "department_id IN (SELECT id FROM \`${TMP_DB}\`.departments WHERE company_id = ${COMPANY_ID})"
copy_table funnel_stage_ai_rules "company_id = ${COMPANY_ID} OR funnel_id IN (SELECT id FROM \`${TMP_DB}\`.funnels WHERE company_id = ${COMPANY_ID})"
copy_table funnel_ai_scenarios "funnel_id IN (SELECT id FROM \`${TMP_DB}\`.funnels WHERE company_id = ${COMPANY_ID})"

copy_table whatsapp_sessions "company_id = ${COMPANY_ID}"
copy_table user_whatsapp_session "whatsapp_session_id IN (SELECT id FROM \`${TMP_DB}\`.whatsapp_sessions WHERE company_id = ${COMPANY_ID})"
copy_table contacts "company_id = ${COMPANY_ID}"
copy_table company_contact "company_id = ${COMPANY_ID}"
copy_table chats "company_id = ${COMPANY_ID}"
copy_table chat_assignments "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table chat_department "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table chat_funnel_transitions "company_id = ${COMPANY_ID}"
copy_table messages "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table message_media "message_id IN (SELECT m.id FROM \`${TMP_DB}\`.messages m INNER JOIN \`${TMP_DB}\`.chats c ON c.id = m.chat_id WHERE c.company_id = ${COMPANY_ID})"
copy_table message_reactions "message_id IN (SELECT m.id FROM \`${TMP_DB}\`.messages m INNER JOIN \`${TMP_DB}\`.chats c ON c.id = m.chat_id WHERE c.company_id = ${COMPANY_ID})"
copy_table message_transcripts "message_id IN (SELECT m.id FROM \`${TMP_DB}\`.messages m INNER JOIN \`${TMP_DB}\`.chats c ON c.id = m.chat_id WHERE c.company_id = ${COMPANY_ID})"
copy_table scheduled_messages "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table ai_orchestrator_runs "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table ai_orchestrator_actions "ai_orchestrator_run_id IN (SELECT r.id FROM \`${TMP_DB}\`.ai_orchestrator_runs r INNER JOIN \`${TMP_DB}\`.chats c ON c.id = r.chat_id WHERE c.company_id = ${COMPANY_ID})"
copy_table ai_response_logs "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table ai_follow_up_proposals "company_id = ${COMPANY_ID} OR chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table knowledge_rules "company_id = ${COMPANY_ID}"
copy_table knowledge_chunks "company_id = ${COMPANY_ID}"
copy_table knowledge_audit_logs "company_id = ${COMPANY_ID}"
copy_table products "company_id = ${COMPANY_ID}"
copy_table services "company_id = ${COMPANY_ID}"

log "COPY calendar_events (clear conflicting ids first) ..."
"${MYSQL[@]}" "$DB_DATABASE" -e "
    SET FOREIGN_KEY_CHECKS=0;
    DELETE FROM calendar_events WHERE id IN (
        SELECT id FROM (
            SELECT id FROM \`${TMP_DB}\`.calendar_events
            WHERE chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})
        ) AS _ce_ids
    );
"
copy_table calendar_events "chat_id IN (SELECT id FROM \`${TMP_DB}\`.chats WHERE company_id = ${COMPANY_ID})"
copy_table entity_memories "tenant_company_id = ${COMPANY_ID}"
copy_table company_tone_profiles "company_id = ${COMPANY_ID}"
copy_table contact_field_definitions "company_id = ${COMPANY_ID}"
copy_table department_posts "department_id IN (SELECT id FROM \`${TMP_DB}\`.departments WHERE company_id = ${COMPANY_ID})"

"${MYSQL[@]}" "$DB_DATABASE" -e "SET FOREIGN_KEY_CHECKS=1;"

log "DONE restore for company_id=${COMPANY_ID}"
"${MYSQL[@]}" "$DB_DATABASE" -e "
SELECT 'chats' t, COUNT(*) c FROM chats WHERE company_id=${COMPANY_ID}
UNION SELECT 'contacts', COUNT(*) FROM contacts WHERE company_id=${COMPANY_ID}
UNION SELECT 'messages', COUNT(*) FROM messages m JOIN chats c ON c.id=m.chat_id WHERE c.company_id=${COMPANY_ID}
UNION SELECT 'wa_sessions', COUNT(*) FROM whatsapp_sessions WHERE company_id=${COMPANY_ID};
"
