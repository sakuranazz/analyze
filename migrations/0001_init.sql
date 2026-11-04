-- 0001: 初期スキーマ（CLAUDE.md 5章 ＋ IMPLEMENTATION.md 5.3・5.4 の提案）
-- 数量は qty_milli（単位の1/1000）、単価は unit_price_micro（円/単位×1000）、液量は ml、金額は円。すべて整数。

-- ===== マスタ =====
CREATE TABLE material_categories (
    id          INTEGER PRIMARY KEY,
    name        TEXT NOT NULL UNIQUE,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

CREATE TABLE capacities (
    id          INTEGER PRIMARY KEY,
    ml          INTEGER NOT NULL UNIQUE CHECK (ml > 0),
    label       TEXT NOT NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

-- 各種コード（ロット用の酒類コード、SKU用の酒類コード、原料コード、タンクコード）
CREATE TABLE lot_liquor_codes (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE TABLE sku_liquor_codes (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE TABLE ingredient_codes (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE TABLE tank_codes (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

CREATE TABLE skus (
    id                  INTEGER PRIMARY KEY,
    code                TEXT NOT NULL UNIQUE,
    name                TEXT NOT NULL DEFAULT '',
    jan_code            TEXT,
    sku_liquor_code_id  INTEGER REFERENCES sku_liquor_codes(id) ON DELETE RESTRICT,
    ingredient_code_id  INTEGER REFERENCES ingredient_codes(id) ON DELETE RESTRICT,
    capacity_id         INTEGER REFERENCES capacities(id) ON DELETE RESTRICT,
    lot_liquor_code_id  INTEGER REFERENCES lot_liquor_codes(id) ON DELETE RESTRICT,
    sort_order          INTEGER NOT NULL DEFAULT 0,
    is_active           INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

-- 資材。瓶は capacity_id で容量と結びつける（容量ごとに1つ）。ラベルとSKUの結びつきは sku_bottle_materials に一本化（CLAUDE.md 8章 #13）
CREATE TABLE materials (
    id           INTEGER PRIMARY KEY,
    category_id  INTEGER NOT NULL REFERENCES material_categories(id) ON DELETE RESTRICT,
    name         TEXT NOT NULL,
    unit         TEXT NOT NULL,
    is_homemade  INTEGER NOT NULL DEFAULT 0 CHECK (is_homemade IN (0, 1)),
    capacity_id  INTEGER REFERENCES capacities(id) ON DELETE RESTRICT,
    sort_order   INTEGER NOT NULL DEFAULT 0,
    is_active    INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE UNIQUE INDEX ux_materials_bottle_capacity ON materials(capacity_id) WHERE capacity_id IS NOT NULL;

CREATE TABLE sku_bottle_materials (
    sku_id                INTEGER NOT NULL REFERENCES skus(id) ON DELETE CASCADE,
    material_id           INTEGER NOT NULL REFERENCES materials(id) ON DELETE RESTRICT,
    qty_per_bottle_milli  INTEGER NOT NULL CHECK (qty_per_bottle_milli > 0),
    PRIMARY KEY (sku_id, material_id)
);

-- 販売区分はコードで持つ（文字列ラベルで判定しない。CLAUDE.md 8章 #14）
CREATE TABLE sales_channels (
    id          INTEGER PRIMARY KEY,
    label       TEXT NOT NULL,
    category    TEXT NOT NULL DEFAULT 'other'
                CHECK (category IN ('wholesale_distributor','wholesale_retailer','retail','other')),
    address     TEXT,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

CREATE TABLE suppliers (
    id          INTEGER PRIMARY KEY,
    name        TEXT NOT NULL,
    address     TEXT,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

-- ===== ロット =====
CREATE TABLE lots (
    id               INTEGER PRIMARY KEY,
    lot_code         TEXT NOT NULL UNIQUE,
    liquor_code_id   INTEGER NOT NULL REFERENCES lot_liquor_codes(id) ON DELETE RESTRICT,
    tank_code_id     INTEGER REFERENCES tank_codes(id) ON DELETE RESTRICT,
    year_month       TEXT NOT NULL CHECK (length(year_month) = 4),   -- YYMM
    sequence_number  INTEGER NOT NULL CHECK (sequence_number > 0),
    status           TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','closed')),
    total_ml         INTEGER CHECK (total_ml IS NULL OR total_ml > 0),
    note             TEXT,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
-- タンクコードが NULL でも重複を防ぐ（SQLite は UNIQUE で NULL を別扱いするため、式インデックス）
CREATE UNIQUE INDEX ux_lots_parts ON lots(liquor_code_id, COALESCE(tank_code_id, 0), year_month, sequence_number);

-- ===== 詰口 =====
CREATE TABLE bottling_sessions (
    id             INTEGER PRIMARY KEY,
    bottling_date  TEXT NOT NULL,
    sku_id         INTEGER NOT NULL REFERENCES skus(id) ON DELETE RESTRICT,
    lot_id         INTEGER NOT NULL REFERENCES lots(id) ON DELETE RESTRICT,
    session_ml     INTEGER NOT NULL CHECK (session_ml > 0),
    note           TEXT,
    created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE INDEX ix_bottling_sessions_lot ON bottling_sessions(lot_id, bottling_date);

CREATE TABLE bottling_items (
    id            INTEGER PRIMARY KEY,
    session_id    INTEGER NOT NULL REFERENCES bottling_sessions(id) ON DELETE CASCADE,
    capacity_id   INTEGER NOT NULL REFERENCES capacities(id) ON DELETE RESTRICT,
    bottle_count  INTEGER NOT NULL CHECK (bottle_count > 0),
    UNIQUE (session_id, capacity_id)
);

CREATE TABLE sample_ml_uses (
    id          INTEGER PRIMARY KEY,
    session_id  INTEGER NOT NULL REFERENCES bottling_sessions(id) ON DELETE CASCADE,
    use_date    TEXT NOT NULL,
    ml          INTEGER NOT NULL CHECK (ml > 0),
    kind        TEXT NOT NULL CHECK (kind IN ('tasting','disposal')),
    note        TEXT,
    created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

CREATE TABLE product_movements (
    id           INTEGER PRIMARY KEY,
    move_date    TEXT NOT NULL,
    sku_id       INTEGER NOT NULL REFERENCES skus(id) ON DELETE RESTRICT,
    capacity_id  INTEGER NOT NULL REFERENCES capacities(id) ON DELETE RESTRICT,
    kind         TEXT NOT NULL CHECK (kind IN ('sale','tasting','disposal','loss','return')),
    count        INTEGER NOT NULL CHECK (count > 0),
    channel_id   INTEGER REFERENCES sales_channels(id) ON DELETE RESTRICT,
    address      TEXT,
    lot_id       INTEGER REFERENCES lots(id) ON DELETE RESTRICT,
    note         TEXT,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE INDEX ix_product_movements ON product_movements(sku_id, capacity_id, move_date);

-- ===== 工程と在庫 =====
CREATE TABLE processes (
    id                 INTEGER PRIMARY KEY,
    process_date       TEXT NOT NULL,
    type               TEXT NOT NULL CHECK (type IN ('polish','germinate','koji','brew','purchase','adjust','stocktake','bottling')),
    lot_id             INTEGER REFERENCES lots(id) ON DELETE RESTRICT,
    source_session_id  INTEGER UNIQUE REFERENCES bottling_sessions(id) ON DELETE CASCADE,
    partner_name       TEXT,
    partner_address    TEXT,
    purchase_date      TEXT,
    note               TEXT,
    created_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    CHECK (type <> 'brew' OR lot_id IS NOT NULL),
    CHECK ((type = 'bottling') = (source_session_id IS NOT NULL))
);
CREATE INDEX ix_processes_date ON processes(process_date, type);
CREATE INDEX ix_processes_purchase_date ON processes(purchase_date);
CREATE INDEX ix_processes_lot ON processes(lot_id);

CREATE TABLE process_lines (
    id                INTEGER PRIMARY KEY,
    process_id        INTEGER NOT NULL REFERENCES processes(id) ON DELETE CASCADE,
    material_id       INTEGER NOT NULL REFERENCES materials(id) ON DELETE RESTRICT,
    qty_milli         INTEGER NOT NULL CHECK (qty_milli <> 0),
    unit_price_micro  INTEGER CHECK (unit_price_micro IS NULL OR unit_price_micro >= 0),
    sort_order        INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX ix_process_lines_material ON process_lines(material_id);
CREATE INDEX ix_process_lines_process ON process_lines(process_id);

-- 工程の効力日: 仕入れは仕入日（空なら工程日）、それ以外は工程日（CLAUDE.md 8章 #11 の仮の決め方）
CREATE VIEW v_process_effective AS
SELECT p.*,
       CASE WHEN p.type = 'purchase' AND p.purchase_date IS NOT NULL AND p.purchase_date <> ''
            THEN p.purchase_date ELSE p.process_date END AS effective_date
FROM processes p;

-- ===== 分析 =====
CREATE TABLE analysis_results (
    id            INTEGER PRIMARY KEY,
    entry_date    TEXT NOT NULL,
    lot_id        INTEGER NOT NULL REFERENCES lots(id) ON DELETE RESTRICT,
    amino_acid    REAL,
    acidity       REAL,
    baume         REAL,
    alcohol       REAL,
    product_temp  REAL,
    room_temp     REAL,
    note          TEXT,
    created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime')),
    updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);
CREATE INDEX ix_analysis_lot ON analysis_results(lot_id, entry_date);

-- 試飲用（session_ml − 瓶の合計ml）は保存せず計算する（CLAUDE.md 8章 #12）
CREATE VIEW v_bottling_sessions AS
SELECT s.*,
       COALESCE((SELECT SUM(c.ml * i.bottle_count) FROM bottling_items i JOIN capacities c ON c.id = i.capacity_id
                 WHERE i.session_id = s.id), 0) AS bottles_ml,
       s.session_ml - COALESCE((SELECT SUM(c.ml * i.bottle_count) FROM bottling_items i JOIN capacities c ON c.id = i.capacity_id
                 WHERE i.session_id = s.id), 0) AS sample_ml,
       COALESCE((SELECT SUM(u.ml) FROM sample_ml_uses u WHERE u.session_id = s.id), 0) AS sample_used_ml
FROM bottling_sessions s;

-- ===== 酒税・設定 =====
CREATE TABLE tax_months (
    ym               TEXT PRIMARY KEY CHECK (length(ym) = 7),   -- YYYY-MM
    declared_amount  INTEGER CHECK (declared_amount IS NULL OR declared_amount >= 0),
    paid_date        TEXT,
    note             TEXT,
    updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now','localtime'))
);

CREATE TABLE settings (
    key    TEXT PRIMARY KEY,
    value  TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES
    ('tax_rate_per_kl', '100000'),
    ('reduced_percent', '80'),
    ('due_months', '2'),
    ('fiscal_year_end_month', '8'),
    ('liquor_year_start_month', '4');

INSERT INTO material_categories (name, sort_order) VALUES
    ('原料米', 10), ('副原料', 20), ('製麹関連', 30), ('醸造関連', 40), ('瓶', 50), ('ラベル', 60);
