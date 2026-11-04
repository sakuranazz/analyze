-- analyze.nnts.jp 新スキーマ (SQLite) — 案
-- 方針: 日付・月単位で持ち、年度は表示時に集計。残高は台帳(stock_movements)の合計で都度計算。

PRAGMA foreign_keys = ON;

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT
);  -- 例: password_hash, liquor_tax_rate_per_kl, liquor_tax_reduction_percent(80), fiscal_end_month(8)

-- ===== マスタ =====
CREATE TABLE material_categories (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, sort_order INTEGER DEFAULT 0
);

CREATE TABLE materials (
    id INTEGER PRIMARY KEY,
    category_id INTEGER NOT NULL REFERENCES material_categories(id),
    name TEXT NOT NULL,
    unit TEXT NOT NULL DEFAULT 'kg',
    kind TEXT NOT NULL DEFAULT 'other',
      -- brown_rice / polished_rice / koji / lactic / yeast / seed_koji / bottle / label / other
    capacity_id INTEGER REFERENCES capacities(id),  -- kind=bottle のとき(容量ごと管理)
    sku_id INTEGER REFERENCES skus(id),             -- kind=label のとき(SKUごと管理)
    usage_per_ml REAL,                              -- 詰口1mlあたり使用量(乳酸・酵母・種麹など)
    is_active INTEGER DEFAULT 1
);

CREATE TABLE suppliers (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, address TEXT, sort_order INTEGER DEFAULT 0);
CREATE TABLE capacities (id INTEGER PRIMARY KEY, label TEXT NOT NULL UNIQUE, ml INTEGER NOT NULL, sort_order INTEGER DEFAULT 0);
CREATE TABLE sales_channels (
    id INTEGER PRIMARY KEY, label TEXT NOT NULL UNIQUE,
    category TEXT DEFAULT 'other', address TEXT, sort_order INTEGER DEFAULT 0
);

-- 作番コード体系
CREATE TABLE sku_liquor_codes (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, description TEXT, sort_order INTEGER DEFAULT 0);
CREATE TABLE sku_capacity_codes (id INTEGER PRIMARY KEY, value TEXT NOT NULL UNIQUE, sort_order INTEGER DEFAULT 0);
CREATE TABLE lot_liquor_codes (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, sort_order INTEGER DEFAULT 0);
CREATE TABLE tank_codes (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, sort_order INTEGER DEFAULT 0);

CREATE TABLE skus (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT, is_active INTEGER DEFAULT 1
);

CREATE TABLE brewing_lots (
    id INTEGER PRIMARY KEY,
    lot_code TEXT NOT NULL UNIQUE,
    liquor_code_id INTEGER REFERENCES lot_liquor_codes(id),
    tank_code_id INTEGER REFERENCES tank_codes(id),
    year_month TEXT NOT NULL,          -- 例 2609
    sequence_number INTEGER NOT NULL,
    note TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ===== 日々の入力 =====
CREATE TABLE analysis_results (
    id INTEGER PRIMARY KEY,
    entry_date TEXT NOT NULL,
    lot_id INTEGER REFERENCES brewing_lots(id),
    sku_id INTEGER REFERENCES skus(id),
    amino_acid REAL, acidity REAL, baume REAL, alcohol REAL,
    product_temp REAL, room_temp REAL,
    note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- 工程イベント(精米/発芽/製麹/醸造/仕入/調整)
CREATE TABLE process_events (
    id INTEGER PRIMARY KEY,
    entry_date TEXT NOT NULL,
    process_type TEXT NOT NULL,   -- seimai / hatsuga / seikiku / jouzou / shiire / chosei
    lot_id INTEGER REFERENCES brewing_lots(id),
    note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- 在庫台帳: 資材(原料・副原料・瓶・ラベル)の入出庫。正=入庫 負=出庫
CREATE TABLE stock_movements (
    id INTEGER PRIMARY KEY,
    entry_date TEXT NOT NULL,
    material_id INTEGER NOT NULL REFERENCES materials(id),
    qty REAL NOT NULL,
    reason TEXT NOT NULL,          -- 仕入/精米/発芽/製麹/醸造/詰口/棚卸調整 等
    amount_yen REAL,               -- 仕入(入庫)の金額。後から修正可
    process_id INTEGER REFERENCES process_events(id),
    lot_id INTEGER REFERENCES brewing_lots(id),
    supplier_id INTEGER REFERENCES suppliers(id),
    note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_mov_material_date ON stock_movements(material_id, entry_date);

-- 詰口・出荷・在庫(完成酒の数量)
CREATE TABLE bottling_events (
    id INTEGER PRIMARY KEY,
    entry_date TEXT NOT NULL,
    event_type TEXT NOT NULL,      -- bottling / sale / tasting / disposal / loss / return
    lot_id INTEGER REFERENCES brewing_lots(id),
    sku_id INTEGER REFERENCES skus(id),
    capacity_id INTEGER REFERENCES capacities(id),
    qty REAL NOT NULL,             -- 本数
    channel_id INTEGER REFERENCES sales_channels(id),
    address TEXT, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_bot_date ON bottling_events(entry_date);

-- ===== 帳簿・集計 =====
-- 目視棚卸し(月末の実数。台帳との乖離は調整として stock_movements に反映)
CREATE TABLE stocktakes (
    id INTEGER PRIMARY KEY,
    year_month TEXT NOT NULL,      -- 例 2610
    material_id INTEGER NOT NULL REFERENCES materials(id),
    counted_qty REAL NOT NULL,
    note TEXT,
    UNIQUE(year_month, material_id)
);

-- 酒税(ロット別)。納付予定額は詰口量から計算、ここには記録だけ持つ
CREATE TABLE lot_tax (
    lot_id INTEGER PRIMARY KEY REFERENCES brewing_lots(id),
    tax_amount INTEGER,
    paid INTEGER DEFAULT 0,
    paid_date TEXT
);

-- 1ml原価は保存せず、ロットの使用原料金額 ÷ 詰口総ml で都度計算(単価修正が即反映されるため)
