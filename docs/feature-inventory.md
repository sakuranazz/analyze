# 旧 analyze.nnts.jp 機能一覧(2026-10-29 バックアップ分から作成)

旧システム: PHP + SQLite(data/sakegura.db)、約5,800行、ログイン1つ。
配色(現行CSS): accent #b5443c / accent-dark #8f342d / bg #faf7f2 / panel #fff / border #e3dcd0 / text #2c2622 / muted #7a7267。

## メニュー(現行)
| 現行メニュー | ファイル | 内容 |
|---|---|---|
| 分析結果入力 / 一覧 | analysis_input, analysis_edit, analysis_results(+pdf) | アミノ酸度・酸度・ボーメ・アルコール・品温・室温・メモ。ロット/SKU紐付け。一覧とPDF |
| 詰口情報 入力 / 一覧 | bottling_input, bottling_edit, bottling_results(+pdf) | 詰口本数(入庫)、販売数・試飲・廃棄・遺失(出庫)、返品、販売先、納品先住所。年度別SKUサマリ。ロット別酒税(軽減80%・100円未満切捨・納付期限・納付済) |
| 工程入力(在庫自動増減) / 在庫一覧 | process_input, inventory_results, inventory_detail, inventory_edit | 精米/発芽/製麹/醸造/仕入/調整の6工程。資材の在庫台帳(入出庫履歴の合計で残高)。仕入先名・住所。年度別の資材サマリ |
| SKU/ロット番号一覧 | sku_lot_list, sku_edit, lot_edit | SKU・醸造ロットの一覧と編集 |
| 資材・SKUマスタ管理 | materials, material_edit, capacity_edit, channel_edit, supplier_edit | 資材カテゴリ/資材、容量、販売先、仕入先 |
| 作番システム | codes, code_edit | SKU作番(酒類コード・容量コード)、醸造ロット作番(酒類コード・タンクコード・年月・回数) |
| その他 | login, logout, change_password, confirm_delete, index | 認証、削除確認、トップ |

## テーブル
material_categories / materials / skus / inventory_ledger / process_events / analysis_results / bottling_capacities / sales_channels / suppliers / bottling_entries / ingredient_codes(旧) / sku_liquor_codes / sku_capacity_codes / lot_liquor_codes / tank_codes / brewing_lots(tax_amount, tax_paid 含む) / settings

## 新システムへの扱い(案・要確認)
- 持ち込む: 分析入力、詰口入力、工程入力(在庫自動増減)、SKU/ロット作番、マスタ、酒税(ロット別)、年度別サマリ
- 新規: 棚卸し(原材料・瓶・ラベル、最終仕入原価法、月次、8月末決算)、1ml原価、日次バックアップ
- 見直し: 年度アーカイブ(4〜3月固定)→日付・月持ちで表示時集計
- 削除候補: skus.jan_code、*.sku_id(未使用・互換用)、brewing_lots.ingredient_code_id(ロットでは未使用)
- 訂正: ingredient_codes はSKUコード(酒類-原料_容量)に使われているため残す
- 未確認: 実際に使っている項目の取捨(どの項目を持ち込むか、ご確認ください)
