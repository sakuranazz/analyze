<?php
declare(strict_types=1);

/**
 * 旧DB → 新DB の移行で、秋広さんの判断が要る項目（CLAUDE.md 7章・8章）。
 * コードではなくデータとしてここに置く。未回答（null や未記載）があると、本番用の移行は止まる。
 * 「仮」と書いたものは、確認が取れたら書き換える（または「確定」にする）。
 */
return [
    // #3 醸造仕込みの台帳行で、ロットが空のもの（旧 inventory_ledger.id => 新しいロットコード）。未回答は null
    'ledger_lot' => [
        61 => null, 62 => null, 64 => null, 65 => null, 83 => null, 84 => null, 86 => null, 87 => null, 88 => null,
        89 => null, 90 => null, 92 => null, 93 => null, 94 => null, 107 => null, 108 => null, 109 => null, 110 => null,
    ],

    // #3 分析結果で、ロットが空のもの（旧 analysis_results.id => ロットコード）。未回答は null
    'analysis_lot' => [42 => null, 43 => null, 44 => null, 45 => null, 46 => null],

    // #4 ロットの全液量（ml）の実測値。書かなければ「詰口の合計」で仮置きする
    'lot_total_ml' => [
        // 'FR-2609_01' => 92700,
    ],

    // #5 販売数が「試飲」「廃棄」「返品」の擬似販売先に入っている行を、その種別の出庫に振り替える（仮）
    //    旧id21〜24（2026-07-15、賞味期限切れ・失敗品）はこれで「廃棄」になる
    'pseudo_channel_kind' => ['tasting' => 'tasting', 'disposal' => 'disposal', 'return' => 'return'],

    // 旧の販売先区分 → 新の区分コード（CLAUDE.md 6.7）
    'channel_category' => [
        'tonya' => 'wholesale_distributor',      // 卸し(問屋) ＝ 卸売業者
        'saketen' => 'wholesale_retailer',       // 卸し(酒販店) ＝ 小売業者
        'gyoumuten' => 'retail',                 // 卸し(業務店) ＝ 小売販売
        'net' => 'retail',                       // 小売(ネット)
        'tenpo' => 'retail',                     // 小売(店頭)
        'other' => 'other',
        'tasting' => 'other', 'disposal' => 'other', 'return' => 'other',   // 擬似販売先は非表示にする
    ],

    // CLAUDE.md 7.3（決定済み）: 端数の容量の扱い。旧容量ml => 新しい扱い
    'capacity_remap' => [
        690 => ['to_ml' => 500],     // 旧id4: 500ml瓶1本＋試飲用190ml
        590 => ['to_ml' => 720],     // 旧id18: 720ml瓶1本として扱う
        70 => ['sample' => true],    // 旧id53: 試飲用70ml（瓶ではない）
    ],
    // CLAUDE.md 7.3: 詰口に足す試飲用（旧 bottling_entries.id => ml）と、その使い道
    'extra_sample_ml' => [4 => 190],
    'sample_uses' => [
        ['entry' => 4, 'date' => '2026-07-15', 'ml' => 190, 'kind' => 'disposal', 'note' => '移行: 旧id4の試飲用190mlを処分（CLAUDE.md 7.3）'],
    ],

    // #6 旧ロットの酒税記録 → 移す月。書かなければ「そのロットの詰口の月」（1か月だけのとき）に移す
    //    検算: FR-2609_01 の 14,900円は 2026年9月分の2ロット合計（187.27L）に一致
    'tax_month_for_lot' => [
        // 'FR-2609_01' => '2026-09',
    ],
    // 旧で「納付済み」だが納付日が記録されていない月の納付日。書かなければ納期限を仮置きし、備考に残す
    'tax_paid_date' => [
        // '2026-06' => '2026-08-20',
    ],

    // 自家製の資材（CLAUDE.md 8章 #15）
    'homemade_materials' => ['白米', '発芽玄米', '米麹'],
];
