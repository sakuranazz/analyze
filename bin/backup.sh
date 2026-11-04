#!/bin/bash
# SQLite の日次バックアップ。cron 例(毎日 3:30):
#   30 3 * * * /home/<user>/<path>/bin/backup.sh >> /home/<user>/<path>/data/backup.log 2>&1
# 世代: 直近 KEEP_DAYS 日分を残す。
set -euo pipefail

BASE="$(cd "$(dirname "$0")/.." && pwd)"
DB="${ANALYZE_DB:-$BASE/data/analyze.db}"
DEST="${BACKUP_DIR:-$BASE/data/backup}"
KEEP_DAYS="${KEEP_DAYS:-30}"

mkdir -p "$DEST"
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="$DEST/analyze_$STAMP.db"

if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "$DB" ".backup '$OUT'"          # 書き込み中でも整合したコピーになる
else
    php -r '$s=new PDO("sqlite:".$argv[1]);$s->exec("VACUUM INTO ".$s->quote($argv[2]));' "$DB" "$OUT"
fi

gzip -f "$OUT"
find "$DEST" -name 'analyze_*.db.gz' -mtime +"$KEEP_DAYS" -delete
echo "$(date '+%F %T') backup ok: $OUT.gz"
