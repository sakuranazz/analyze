#!/usr/bin/env bash
# 反映（IMPLEMENTATION.md 11.3）。SSH が使える前提。本番のDB・設定・var/ には触れない。
#
# 事前に設定するもの（リポジトリには入れない。~/.analyze-deploy などに書いて source する）:
#   DEPLOY_HOST    例: xs123456@xs123456.xsrv.jp   （~/.ssh/config の Host 名でも可。ポートは ssh 設定で）
#   DEPLOY_APP     例: /home/xs123456/analyze-app   （アプリ本体。公開ディレクトリの外）
#   DEPLOY_PUBLIC  例: /home/xs123456/nnts.jp/public_html/analyze2   （公開ディレクトリ）
#   DEPLOY_PHP     例: /usr/bin/php8.3              （tools/env_check.php が表示したパス）
#   DEPLOY_URL     例: https://analyze2.nnts.jp
#   BASIC_USER / BASIC_PASS  スモークテスト用（環境変数か macOS キーチェーンから。例:
#                  BASIC_PASS=$(security find-generic-password -s analyze-basic -w)）
#
# 使い方:
#   tools/deploy.sh --dry-run   転送の差分を表示するだけ
#   tools/deploy.sh             本実行（確認あり）
set -euo pipefail
cd "$(dirname "$0")/.."

: "${DEPLOY_HOST:?DEPLOY_HOST を設定してください}"
: "${DEPLOY_APP:?DEPLOY_APP を設定してください}"
: "${DEPLOY_PUBLIC:?DEPLOY_PUBLIC を設定してください}"
: "${DEPLOY_PHP:=php}"
DRY=""
[[ "${1:-}" == "--dry-run" ]] && DRY="--dry-run"

ssh_run() { ssh "$DEPLOY_HOST" "$@"; }

echo "== 1. テスト"
php tests/run.php > /tmp/analyze-test.log || { cat /tmp/analyze-test.log; echo "テストが通らないため止めます"; exit 1; }
find src public tools templates -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

# 転送するもの（--delete はこの範囲の中だけ。DB・var・設定は対象外）
# 公開ディレクトリの .htaccess / .htpasswd（Xserver のパネルで設定した Basic 認証）は絶対に消さない・上書きしない
RSYNC=(rsync -az --delete --itemize-changes $DRY --exclude '.DS_Store' --exclude '.htaccess' --exclude '.htpasswd')
APP_DIRS=(src templates migrations tools)

echo "== 2. 差分"
for d in "${APP_DIRS[@]}"; do "${RSYNC[@]}" --dry-run "$d/" "$DEPLOY_HOST:$DEPLOY_APP/$d/"; done
"${RSYNC[@]}" --dry-run --exclude '_app_root.php' public/ "$DEPLOY_HOST:$DEPLOY_PUBLIC/"
if [[ -n "$DRY" ]]; then echo "（--dry-run のため、ここで終わります）"; exit 0; fi

read -r -p "反映しますか？ [y/N] " ans
[[ "$ans" == "y" ]] || { echo "やめました"; exit 1; }

echo "== 3. 反映前のバックアップ"
ssh_run "mkdir -p '$DEPLOY_APP/var' && cd '$DEPLOY_APP' && [ ! -f tools/backup.php ] || $DEPLOY_PHP tools/backup.php --label pre-deploy"

echo "== 4. 保守モード（全ページ 503）"
ssh_run "touch '$DEPLOY_APP/var/maintenance.flag'"
trap 'echo "失敗しました。保守モードのままです。原因を確認し、直前のタグを反映し直すか、ssh で rm $DEPLOY_APP/var/maintenance.flag してください"' ERR

echo "== 5. 転送"
ssh_run "mkdir -p '$DEPLOY_APP' '$DEPLOY_PUBLIC'"
for d in "${APP_DIRS[@]}"; do "${RSYNC[@]}" "$d/" "$DEPLOY_HOST:$DEPLOY_APP/$d/"; done
rsync -az config/config.php "$DEPLOY_HOST:$DEPLOY_APP/config/config.php"
"${RSYNC[@]}" --exclude '_app_root.php' public/ "$DEPLOY_HOST:$DEPLOY_PUBLIC/"
ssh_run "printf '%s\n' '<?php' 'return \"$DEPLOY_APP\";' > '$DEPLOY_PUBLIC/_app_root.php'"
ssh_run "[ -f '$DEPLOY_APP/var/maintenance.flag' ] || touch '$DEPLOY_APP/var/maintenance.flag'"

echo "== 6. マイグレーション（内部でも適用前にバックアップ）"
ssh_run "cd '$DEPLOY_APP' && $DEPLOY_PHP tools/migrate.php"

echo "== 7. 確認（不変条件。要注意があっても反映は続ける）"
ssh_run "cd '$DEPLOY_APP' && $DEPLOY_PHP tools/check.php" || echo "（要注意があります。入口の画面で確認してください）"

echo "== 8. 保守モードを解除"
ssh_run "rm -f '$DEPLOY_APP/var/maintenance.flag'"
trap - ERR

echo "== 9. スモークテスト"
if [[ -n "${DEPLOY_URL:-}" ]]; then
  code=$(curl -s -o /dev/null -w '%{http_code}' "$DEPLOY_URL/healthz.php")
  echo "  認証なし: $code（401 であること）"; [[ "$code" == "401" ]] || echo "  ✗ Basic 認証が効いていません！ .htaccess を確認してください"
  if [[ -n "${BASIC_USER:-}" && -n "${BASIC_PASS:-}" ]]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' -u "$BASIC_USER:$BASIC_PASS" "$DEPLOY_URL/healthz.php")
    echo "  認証あり: $code（200 であること）"
  fi
fi
git tag -a "v$(date +%Y.%m.%d-%H%M)" -m "反映 $(date '+%Y-%m-%d %H:%M')" 2>/dev/null || true
echo "完了しました"
