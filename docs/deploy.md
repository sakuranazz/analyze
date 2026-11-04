# 新 analyze の公開と移行(Xserver)

旧 analyze.nnts.jp はそのまま残し、新システムは別の場所で並行運用する。

## 1. 置き場所を作る
- Xserver のサーバーパネルでサブドメイン(例: `analyze2.nnts.jp`)を追加し、無料SSLを有効にする。
- このリポジトリの中身を、そのサブドメインの公開フォルダにアップロードする(`data/*.db` は含めない)。
- `data/` フォルダが PHP から書き込めることを確認する(通常は 755 でよい)。

## 2. 旧データを移行する(SSH)
```sh
cd ~/nnts.jp/public_html/analyze2.nnts.jp     # 置いた場所に合わせる
php bin/migrate_from_old.php ~/nnts.jp/public_html/analyze.nnts.jp/data/sakegura.db data/analyze.db
```
- 旧DBは読み取りのみで、変更しない。
- 最後に「突き合わせはすべて一致しました」と出ればOK。差が出たら、表示された項目を確認する。
- パスワードは旧システムと同じものでログインできる。
- 並行運用中に旧システムへ入力した分を取り込み直すときは、`--force` を付けて作り直す(新システム側で入力した分は消えるので注意)。

## 3. 日次バックアップ(cron)
サーバーパネル → Cron設定 に追加(毎日 3:30 の例):
```
30 3 * * * /home/<サーバーID>/nnts.jp/public_html/analyze2.nnts.jp/bin/backup.sh >> /home/<サーバーID>/nnts.jp/public_html/analyze2.nnts.jp/data/backup.log 2>&1
```
- `data/backup/` に 30日分を残す。Webからは見えない(`data/.htaccess`)。

## 4. 移行後にやること
- 在庫・棚卸し → 各資材の「履歴」で、最新の仕入の金額を入れる(旧システムには金額がないため。入れるまで評価額は0円)。
- マスタ管理 → 資材: 瓶は「容量」、ラベルは「SKU」を設定する。乳酸・酵母などは必要なら「詰口1mlあたり使用量」を設定する。
- ロット未設定だった旧データの詰口は、酒税の月別一覧に出ない(旧システムでは納付済扱い)。

## 移行の対応(参考)
| 旧 | 新 |
|---|---|
| inventory_ledger | stock_movements(仕入の相手先は仕入先マスタに結びつけ、その他の相手先はメモへ) |
| bottling_entries(1行に詰口・販売・試飲などが混在) | bottling_events(種類ごとの行に分割。同じ旧行は同じ batch) |
| bottling_capacities | capacities(ラベルから ml を取り出す) |
| brewing_lots.tax_paid(ロット単位) | tax_payments(月の全ロットが納付済なら月を納付済) |
| skus.jan_code、brewing_lots.ingredient_code_id | 持ち込まない |
| 資材の種別 | 名前から自動判定(玄米・白米・発芽玄米・米麹・種麹・乳酸・酵母、それ以外は「その他」) |
