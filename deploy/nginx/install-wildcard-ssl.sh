#!/usr/bin/env bash
# Подключает wildcard-сертификат к catchall-блоку *.accel.kz в nginx.
set -euo pipefail

CERT_NAME="${CERT_NAME:-accel.kz-wildcard}"
LIVE="/etc/letsencrypt/live/${CERT_NAME}"
NGINX_SITE="${NGINX_SITE:-/etc/nginx/sites-available/accel.kz}"

if [[ ! -f "${LIVE}/fullchain.pem" ]]; then
    echo "Сертификат не найден: ${LIVE}. Сначала: sudo deploy/nginx/issue-wildcard-cert.sh" >&2
    exit 1
fi

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите от root." >&2
    exit 1
fi

# Заменить cert только в catchall-блоке (*.accel.kz) — после последнего server_name *.accel.kz
python3 - <<'PY' "${NGINX_SITE}" "${CERT_NAME}"
import pathlib, re, sys
path = pathlib.Path(sys.argv[1])
cert = sys.argv[2]
text = path.read_text()
marker = "server_name *.accel.kz;"
idx = text.rfind(marker)
if idx < 0:
    raise SystemExit("catchall server block not found")
block = text[idx:]
if f"/etc/letsencrypt/live/{cert}/" in block:
    print("Already using wildcard cert")
    sys.exit(0)
block_new = re.sub(
    r"ssl_certificate\s+\S+;\n\s*ssl_certificate_key\s+\S+;",
    f"ssl_certificate     /etc/letsencrypt/live/{cert}/fullchain.pem;\n"
    f"    ssl_certificate_key /etc/letsencrypt/live/{cert}/privkey.pem;",
    block,
    count=1,
)
path.write_text(text[:idx] + block_new + text[idx + len(block):])
PY

nginx -t
systemctl reload nginx
echo "Wildcard SSL подключён для *.accel.kz (${CERT_NAME})"
