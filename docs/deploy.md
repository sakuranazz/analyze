# 新 analyze の公開(旧 analyze.nnts.jp に上書き)

方針: 旧サイト一式は**消さずに公開フォルダの外へ退避**し、空いた場所に新システムを置く。
旧DB(sakegura.db)は読み取るだけで変更しない。問題があれば、フォルダを戻すだけで旧サイトに戻せる。

パス(サーバーID xs684527):
- 公開フォルダ: `/home/xs684527/nnts.jp/public_html/analyze.nnts.jp`
- Basic認証: `/home/xs684527/nnts.jp/htpasswd/analyze.nnts.jp/.htpasswd`(新しい .htaccess でも同じものを使う)

## 1. 旧サイトを丸ごとバックアップ(SSH)
```sh
cd ~/nnts.jp/public_html
tar czf ~/analyze_old_20261104.tar.gz analyze.nnts.jp
ls -la ~/analyze_old_20261104.tar.gz
```

## 2. 新システムを公開フォルダの外で準備し、移行する
```sh
cd ~
rm -rf analyze_new && unzip -q analyze_new.zip            # ~/analyze_new ができる
php -v                                                     # 8.x でなければ以降 php8.3 に置き換え
php analyze_new/bin/migrate_from_old.php ~/nnts.jp/public_html/analyze.nnts.jp/data/sakegura.db analyze_new/data/analyze.db
chmod +x analyze_new/bin/backup.sh
```
「突き合わせはすべて一致しました」と出るまで次に進まない。

## 3. 入れ替える(数秒で終わる)
```sh
cd ~/nnts.jp/public_html
mv analyze.nnts.jp ~/analyze_old_site                      # 旧サイトを公開フォルダの外へ(そのまま残る)
mv ~/analyze_new analyze.nnts.jp
```

### 元に戻すとき
```sh
cd ~/nnts.jp/public_html
mv analyze.nnts.jp ~/analyze_new_failed
mv ~/analyze_old_site analyze.nnts.jp
```

## 4. 確認
- https://analyze.nnts.jp を開く → Basic認証 → 新システムのログイン(パスワードは旧システムと同じ)。
- https://analyze.nnts.jp/data/analyze.db が 403 になること(DBが外から見えない)。

## 5. 日次バックアップ(cron)
サーバーパネル → Cron設定 に追加(毎日 3:30):
```
30 3 * * * /home/xs684527/nnts.jp/public_html/analyze.nnts.jp/bin/backup.sh >> /home/xs684527/nnts.jp/public_html/analyze.nnts.jp/data/backup.log 2>&1
```
`data/backup/` に30日分を残す。Webからは見えない。

## 6. 移行後にやること
- 在庫・棚卸し → 各資材の「履歴」で、最新の仕入の金額を入れる(旧システムには金額がないため。入れるまで評価額は0円)。
- マスタ管理 → 資材: 瓶は「容量」、ラベルは「SKU」を設定。乳酸・酵母などは必要なら「詰口1mlあたり使用量」を設定。
- ロット未設定だった旧データの詰口8件は、酒税の月別一覧に出ない(旧システムでは納付済扱い)。
- 旧サイト(~/analyze_old_site)と バックアップ(~/analyze_old_20261104.tar.gz)は、しばらく残しておく。

## 移行の対応(参考)
| 旧 | 新 |
|---|---|
| inventory_ledger | stock_movements(仕入の相手先は仕入先マスタに結びつけ、その他の相手先はメモへ) |
| bottling_entries(1行に詰口・販売・試飲などが混在) | bottling_events(種類ごとの行に分割。同じ旧行は同じ batch) |
| bottling_capacities | capacities(ラベルから ml を取り出す) |
| brewing_lots.tax_paid(ロット単位) | tax_payments(月の全ロットが納付済なら月を納付済) |
| skus.jan_code、brewing_lots.ingredient_code_id | 持ち込まない |
| 資材の種別 | 名前から自動判定(玄米・白米・発芽玄米・米麹・種麹・乳酸・酵母、それ以外は「その他」) |
