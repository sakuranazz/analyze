<?php
declare(strict_types=1);

namespace App\Ops;

use App\Db\Connection;
use App\Domain\CodeGenerator;
use App\Domain\LiquorTax;

/**
 * 旧DB（data/sakegura.db）→ 新DB の取り込み（CLAUDE.md 7章、IMPLEMENTATION.md 9章）。
 * - 旧DBは読み取り専用で開く。新DBは毎回作り直す前提（呼び出し側でマイグレーション済みの空DBを渡す）。
 * - 判断が要る項目は config/migration_decisions.php。未回答があれば unresolved に積み、
 *   allowUnresolved=false なら取り込まずに止まる。true（リハーサル）なら仮のロットに置く。
 */
final class LegacyImport
{
    private const TYPE_MAP = [
        'seimai' => 'polish', 'hatsuga' => 'germinate', 'seikiku' => 'koji',
        'jouzou' => 'brew', 'shiire' => 'purchase', 'chosei' => 'adjust',
    ];
    /** 理由の文字列と工程の種別が食い違っていないかの確認用（分岐には使わない） */
    private const REASON_PREFIX = [
        'polish' => '精米', 'germinate' => '発芽処理', 'koji' => '製麹', 'brew' => '醸造仕込み',
        'purchase' => '仕入', 'adjust' => '手動調整', 'stocktake' => '棚卸し調整',
    ];
    public const PLACEHOLDER_LOT = 'UNASSIGNED-0000_01';

    /** @var array<string, list<string>> 報告（見出し => 行） */
    public array $report = [];
    /** @var list<string> 未解決 */
    public array $unresolved = [];
    /** @var array<string, int> */
    public array $counts = [];

    /** @var array<int, int> 旧id => 新id */
    private array $matMap = [];
    private array $capByOldId = [];      // 旧容量id => ['ml'=>, 'new_id'=>?int, 'sample'=>bool]
    private array $skuMap = [];
    private array $lotMap = [];          // 旧ロットid => 新ロットid
    private array $lotCodeToNew = [];    // ロットコード => 新ロットid
    private array $channelMap = [];      // 旧id => ['new_id'=>, 'category'=>旧category]
    private ?int $placeholderLot = null;

    /** @param array<string, mixed> $decisions */
    public function __construct(private \PDO $old, private Connection $new, private array $decisions, private bool $allowUnresolved)
    {
        $this->old->exec('PRAGMA query_only = ON');
    }

    public static function openOld(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }

    /** @return bool 取り込んだか（未解決で止まったら false） */
    public function run(): bool
    {
        $this->checkUnresolved();
        if ($this->unresolved !== [] && !$this->allowUnresolved) {
            return false;
        }
        $this->new->transaction(function (): void {
            $this->masters();
            $this->lots();
            $this->processes();
            $this->analysis();
            $this->bottling();
            $this->tax();
            $this->settings();
            $this->lotTotals();
        });
        return true;
    }

    // ---------------------------------------------------------------- 未解決の確認

    private function checkUnresolved(): void
    {
        $lotCodes = array_column($this->old->query('SELECT lot_code FROM brewing_lots')->fetchAll(), 'lot_code');
        $check = function (string $kind, array $ids, array $map) use ($lotCodes): void {
            foreach ($ids as $id) {
                $v = $map[$id] ?? null;
                if ($v === null) {
                    $this->unresolved[] = "{$kind} 旧id {$id}: ロットが未指定";
                } elseif (!in_array($v, $lotCodes, true)) {
                    $this->unresolved[] = "{$kind} 旧id {$id}: ロットコード {$v} が旧DBにありません";
                }
            }
        };
        $brewNoLot = array_map('intval', array_column($this->old->query(
            "SELECT l.id FROM inventory_ledger l LEFT JOIN process_events p ON p.id = l.process_id
             WHERE l.lot_id IS NULL AND p.process_type = 'jouzou' ORDER BY l.id")->fetchAll(), 'id'));
        $check('醸造仕込みの台帳行', $brewNoLot, $this->decisions['ledger_lot'] ?? []);
        $anaNoLot = array_map('intval', array_column($this->old->query('SELECT id FROM analysis_results WHERE lot_id IS NULL ORDER BY id')->fetchAll(), 'id'));
        $check('分析結果', $anaNoLot, $this->decisions['analysis_lot'] ?? []);

        if ($brewNoLot !== [] || $anaNoLot !== []) {
            $this->suggestLots($brewNoLot, $anaNoLot);
        }
    }

    /** 未解決のロットの候補（日付が近い仕込みのあるロット）を報告に出す */
    private function suggestLots(array $ledgerIds, array $analysisIds): void
    {
        $brews = $this->old->query("SELECT DISTINCT l.entry_date, b.lot_code FROM inventory_ledger l JOIN brewing_lots b ON b.id = l.lot_id
            JOIN process_events p ON p.id = l.process_id WHERE p.process_type = 'jouzou'")->fetchAll();
        $near = function (string $date, int $days) use ($brews): string {
            $c = [];
            foreach ($brews as $b) {
                if (abs((strtotime($b['entry_date']) - strtotime($date)) / 86400) <= $days) {
                    $c[$b['lot_code']] = true;
                }
            }
            return $c === [] ? '（近い仕込みなし）' : implode('、', array_keys($c));
        };
        $lines = [];
        $st = $this->old->prepare('SELECT l.id, l.entry_date, m.name, l.change_amount, m.unit FROM inventory_ledger l JOIN materials m ON m.id = l.material_id WHERE l.id = ?');
        foreach ($ledgerIds as $id) {
            $st->execute([$id]);
            $r = $st->fetch();
            $lines[] = "| 台帳 {$id} | {$r['entry_date']} | {$r['name']} {$r['change_amount']}{$r['unit']} | {$near($r['entry_date'], 7)} |";
        }
        $st = $this->old->prepare('SELECT id, entry_date, note FROM analysis_results WHERE id = ?');
        foreach ($analysisIds as $id) {
            $st->execute([$id]);
            $r = $st->fetch();
            $lines[] = "| 分析 {$id} | {$r['entry_date']} | " . ($r['note'] ?: '—') . " | {$near($r['entry_date'], 10)} |";
        }
        $this->report['ロットが空の行と、日付が近い仕込みのあるロット（候補）'] = array_merge(['| 旧の行 | 日付 | 内容 | 候補 |', '|---|---|---|---|'], $lines);
    }

    // ---------------------------------------------------------------- マスタ

    private function masters(): void
    {
        $catByName = array_column($this->new->all('SELECT id, name FROM material_categories'), 'id', 'name');
        $catOld = [];
        foreach ($this->old->query('SELECT * FROM material_categories') as $c) {
            $catOld[(int) $c['id']] = $catByName[$c['name']] ?? $this->new->insert('material_categories', ['name' => $c['name'], 'sort_order' => (int) $c['sort_order'] * 10]);
        }
        $homemade = $this->decisions['homemade_materials'] ?? [];
        foreach ($this->old->query('SELECT * FROM materials ORDER BY id') as $m) {
            $this->matMap[(int) $m['id']] = $this->new->insert('materials', [
                'category_id' => $catOld[(int) $m['category_id']], 'name' => $m['name'], 'unit' => $m['unit'],
                'is_homemade' => in_array($m['name'], $homemade, true) ? 1 : 0, 'sort_order' => (int) $m['id'],
                'is_active' => (int) $m['is_active'],
            ]);
        }
        $this->counts['資材'] = count($this->matMap);

        $remap = $this->decisions['capacity_remap'] ?? [];
        $newCapByMl = [];
        foreach ($this->old->query('SELECT * FROM bottling_capacities ORDER BY sort_order, id') as $c) {
            $ml = (int) $c['label'];
            if (isset($remap[$ml])) {
                continue;
            }
            $newCapByMl[$ml] = $this->new->insert('capacities', ['ml' => $ml, 'label' => $ml . 'ml', 'sort_order' => (int) $c['sort_order']]);
        }
        foreach ($this->old->query('SELECT * FROM bottling_capacities') as $c) {
            $ml = (int) $c['label'];
            $r = $remap[$ml] ?? null;
            $this->capByOldId[(int) $c['id']] = [
                'ml' => $ml,
                'new_id' => $r === null ? $newCapByMl[$ml] : (isset($r['to_ml']) ? ($newCapByMl[$r['to_ml']] ?? null) : null),
                'sample' => (bool) ($r['sample'] ?? false),
            ];
        }
        $this->report['容量の置き換え（CLAUDE.md 7.3）'] = array_map(
            static fn ($ml, $r) => "- {$ml}ml → " . (isset($r['to_ml']) ? "{$r['to_ml']}ml" : '試飲用（瓶ではない）'),
            array_keys($remap), $remap
        );
        $this->counts['容量'] = count($newCapByMl);

        $codeMaps = [];
        foreach (['lot_liquor_codes', 'sku_liquor_codes', 'ingredient_codes', 'tank_codes'] as $t) {
            foreach ($this->old->query("SELECT * FROM {$t} ORDER BY sort_order, id") as $r) {
                $codeMaps[$t][(int) $r['id']] = $this->new->insert($t, ['code' => $r['code'], 'name' => $r['name'], 'sort_order' => (int) $r['sort_order']]);
                $codeMaps[$t . '_by_code'][$r['code']] = $codeMaps[$t][(int) $r['id']];
            }
        }
        $this->codeMaps = $codeMaps;

        foreach ($this->old->query('SELECT * FROM skus ORDER BY id') as $s) {
            $parts = preg_match('/^([A-Z]+)(?:-([A-Z]+))?_(\d+)$/', (string) $s['code'], $m) === 1 ? $m : null;
            $this->skuMap[(int) $s['id']] = $this->new->insert('skus', [
                'code' => $s['code'], 'name' => (string) ($s['name'] ?? ''),
                'jan_code' => ($s['jan_code'] === null || $s['jan_code'] === 'なし') ? null : $s['jan_code'],
                'sku_liquor_code_id' => $parts ? ($codeMaps['sku_liquor_codes_by_code'][$parts[1]] ?? null) : null,
                'ingredient_code_id' => ($parts && $parts[2] !== '') ? ($codeMaps['ingredient_codes_by_code'][$parts[2]] ?? null) : null,
                'capacity_id' => $parts ? ($newCapByMl[(int) $parts[3]] ?? null) : null,
                'lot_liquor_code_id' => $s['lot_liquor_code_id'] === null ? null : ($codeMaps['lot_liquor_codes'][(int) $s['lot_liquor_code_id']] ?? null),
                'sort_order' => (int) $s['id'], 'is_active' => (int) $s['is_active'],
            ]);
        }
        $this->counts['SKU'] = count($this->skuMap);

        $catMap = $this->decisions['channel_category'] ?? [];
        foreach ($this->old->query('SELECT * FROM sales_channels ORDER BY sort_order, id') as $c) {
            $old = (string) ($c['category'] ?? 'other');
            $pseudo = in_array($old, ['tasting', 'disposal', 'return'], true);
            $this->channelMap[(int) $c['id']] = [
                'new_id' => $this->new->insert('sales_channels', [
                    'label' => $c['label'], 'category' => $catMap[$old] ?? 'other', 'address' => $c['address'] ?? null,
                    'sort_order' => (int) $c['sort_order'], 'is_active' => $pseudo ? 0 : 1,
                ]),
                'category' => $old,
            ];
        }
        foreach ($this->old->query('SELECT * FROM suppliers ORDER BY sort_order, id') as $s) {
            $this->new->insert('suppliers', ['name' => $s['name'], 'address' => $s['address'], 'sort_order' => (int) $s['sort_order']]);
        }
    }

    /** @var array<string, array<int|string, int>> */
    private array $codeMaps = [];

    private function lots(): void
    {
        foreach ($this->old->query('SELECT * FROM brewing_lots ORDER BY id') as $l) {
            $id = $this->new->insert('lots', [
                'lot_code' => $l['lot_code'],
                'liquor_code_id' => $this->codeMaps['lot_liquor_codes'][(int) $l['liquor_code_id']],
                'tank_code_id' => $l['tank_code_id'] === null ? null : $this->codeMaps['tank_codes'][(int) $l['tank_code_id']],
                'year_month' => $l['year_month'], 'sequence_number' => (int) $l['sequence_number'],
                'total_ml' => ($l['liquor_volume_ml'] !== null && (float) $l['liquor_volume_ml'] > 0) ? (int) round((float) $l['liquor_volume_ml']) : null,
                'note' => ($l['note'] === null || $l['note'] === '') ? null : $l['note'],
                'created_at' => str_replace(' ', 'T', (string) $l['created_at']),
            ]);
            $this->lotMap[(int) $l['id']] = $id;
            $this->lotCodeToNew[(string) $l['lot_code']] = $id;
            $parsed = CodeGenerator::parseLot((string) $l['lot_code']);
            if ($parsed === null) {
                $this->report['ロットコードの形式'][] = "- {$l['lot_code']}: 形式が想定と違います（そのまま移しました）";
            }
        }
        $this->counts['ロット'] = count($this->lotMap);
    }

    private function placeholderLot(): int
    {
        if ($this->placeholderLot === null) {
            $liquor = (int) $this->new->value('SELECT id FROM lot_liquor_codes ORDER BY id LIMIT 1');
            $this->placeholderLot = $this->new->insert('lots', [
                'lot_code' => self::PLACEHOLDER_LOT, 'liquor_code_id' => $liquor, 'year_month' => '0001', 'sequence_number' => 1,
                'status' => 'closed', 'note' => '移行リハーサル用の仮置き。判断が出たら移行をやり直すと消えます',
            ]);
        }
        return $this->placeholderLot;
    }

    private function lotFor(?int $oldLotId, ?string $decided): ?int
    {
        if ($decided !== null) {
            return $this->lotCodeToNew[$decided];
        }
        return $oldLotId === null ? null : $this->lotMap[$oldLotId];
    }

    // ---------------------------------------------------------------- 工程

    private function processes(): void
    {
        $ledger = $this->old->query("SELECT l.*, p.process_type, p.entry_date AS p_date, p.lot_id AS p_lot, p.note AS p_note
            FROM inventory_ledger l LEFT JOIN process_events p ON p.id = l.process_id ORDER BY l.process_id, l.id")->fetchAll();
        $groups = [];
        foreach ($ledger as $r) {
            $key = $r['process_id'] === null ? 'L' . $r['id'] : 'P' . $r['process_id'];
            $groups[$key][] = $r;
        }
        $decided = $this->decisions['ledger_lot'] ?? [];
        $dateDiffs = [];
        $lotDiffs = 0;
        $priceRounded = 0;
        $priceMaxYen = 0.0;
        $qtyIssues = [];
        $noProduct = [];
        $splits = 0;
        $n = 0;
        foreach ($groups as $rows) {
            $first = $rows[0];
            $type = self::TYPE_MAP[(string) $first['process_type']] ?? $this->typeFromReason((string) $first['reason']);
            if (!str_starts_with((string) $first['reason'], self::REASON_PREFIX[$type])) {
                $this->report['理由と種別の食い違い'][] = "- 台帳 {$first['id']}: 種別 {$type} / 理由「{$first['reason']}」";
            }
            // 1つの旧工程で行ごとにロット・日付が違うときは、ロット・日付ごとに工程を分ける（IMPLEMENTATION.md 9.1）
            $byKey = [];
            foreach ($rows as $r) {
                $lotDecision = $r['lot_id'] === null && $type === 'brew' ? ($decided[(int) $r['id']] ?? null) : null;
                $lotId = $this->lotFor($r['lot_id'] === null ? null : (int) $r['lot_id'], $lotDecision);
                if ($type === 'brew' && $lotId === null) {
                    $lotId = $this->placeholderLot();
                }
                $byKey[$r['entry_date'] . '|' . ($lotId ?? 0)][] = $r + ['__lot' => $lotId];
            }
            if (count($byKey) > 1) {
                $splits++;
            }
            if ($first['p_date'] !== null && $first['p_date'] !== $first['entry_date']) {
                $dateDiffs[] = "- 工程 {$first['process_id']}: 工程の日付 {$first['p_date']} → 台帳の日付 {$first['entry_date']} を採用";
            }
            if ($first['process_id'] !== null && (int) ($first['p_lot'] ?? 0) !== (int) ($first['lot_id'] ?? 0)) {
                $lotDiffs++;
            }
            foreach ($byKey as $part) {
                $p = $part[0];
                $pid = $this->new->insert('processes', [
                    'process_date' => $p['entry_date'], 'type' => $type, 'lot_id' => $p['__lot'],
                    'partner_name' => $type === 'purchase' ? ($p['partner_name'] ?: null) : null,
                    'partner_address' => $type === 'purchase' ? ($p['partner_address'] ?: null) : null,
                    'purchase_date' => $type === 'purchase' ? ($p['purchase_date'] ?: null) : null,
                    'note' => trim(($p['p_note'] ?? '') . ' ' . ($p['note'] ?? '')) ?: null,
                ]);
                $n++;
                $hasPlus = false;
                foreach ($part as $i => $r) {
                    $qty = (float) $r['change_amount'];
                    $qm = (int) round($qty * 1000);
                    if (abs($qty * 1000 - $qm) > 1e-6) {
                        $qtyIssues[] = "- 台帳 {$r['id']}: 数量 {$qty} → " . ($qm / 1000);
                    }
                    $price = null;
                    if ($r['unit_price'] !== null && $qm > 0) {
                        $pf = (float) $r['unit_price'];
                        $price = (int) round($pf * 1_000_000);
                        if (abs($pf * 1_000_000 - $price) > 1e-3) {
                            $priceRounded++;
                            $priceMaxYen = max($priceMaxYen, abs($pf - $price / 1_000_000) * $qty);
                        }
                    }
                    $hasPlus = $hasPlus || $qm > 0;
                    $this->new->insert('process_lines', [
                        'process_id' => $pid, 'material_id' => $this->matMap[(int) $r['material_id']],
                        'qty_milli' => $qm, 'unit_price_micro' => $price, 'sort_order' => $i,
                    ]);
                }
                if (in_array($type, ['polish', 'germinate', 'koji'], true) && !$hasPlus) {
                    $noProduct[] = "- 工程 {$first['process_id']}（{$p['entry_date']}・{$type}）: できた品目の行がありません。原料だけ減り、単価は作られません";
                }
            }
        }
        $empty = (int) $this->old->query('SELECT COUNT(*) FROM process_events p WHERE NOT EXISTS (SELECT 1 FROM inventory_ledger l WHERE l.process_id = p.id)')->fetchColumn();
        $this->counts['工程（台帳行のある旧工程）'] = count($groups);
        $this->counts['工程（新）'] = $n;
        $this->counts['台帳行'] = count($ledger);
        $this->report['工程の取り込み'] = [
            "- 台帳の行を持たない空の工程 {$empty} 件は移しませんでした（CLAUDE.md 7.2）",
            "- 工程と台帳でロットが食い違う工程 {$lotDiffs} 件は、台帳側のロットを正としました",
            "- 行ごとにロット・日付が違うため分割した工程: {$splits} 件",
            "- 単価を 0.000001円 単位に丸めた行: {$priceRounded} 件（金額への影響は最大 " . number_format($priceMaxYen, 4) . ' 円/行）',
        ];
        if ($dateDiffs !== []) {
            $this->report['工程の日付と台帳の日付が違うもの（台帳の日付を採用）'] = $dateDiffs;
        }
        if ($qtyIssues !== []) {
            $this->report['小数3桁を超える数量（丸めた）'] = $qtyIssues;
        }
        if ($noProduct !== []) {
            $this->report['できた品目のない製造工程'] = $noProduct;
        }
    }

    private function typeFromReason(string $reason): string
    {
        foreach (self::REASON_PREFIX as $type => $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return $type;
            }
        }
        throw new \RuntimeException("対応表にない理由です: {$reason}");
    }

    // ---------------------------------------------------------------- 分析

    private function analysis(): void
    {
        $decided = $this->decisions['analysis_lot'] ?? [];
        $n = 0;
        foreach ($this->old->query('SELECT * FROM analysis_results ORDER BY id') as $a) {
            $lot = $this->lotFor($a['lot_id'] === null ? null : (int) $a['lot_id'], $a['lot_id'] === null ? ($decided[(int) $a['id']] ?? null) : null)
                ?? $this->placeholderLot();
            $this->new->insert('analysis_results', [
                'entry_date' => $a['entry_date'], 'lot_id' => $lot,
                'amino_acid' => $a['amino_acid'], 'acidity' => $a['acidity'], 'baume' => $a['baume'], 'alcohol' => $a['alcohol'],
                'product_temp' => $a['product_temp'], 'room_temp' => $a['room_temp'],
                'note' => ($a['note'] === null || $a['note'] === '') ? null : $a['note'],
            ]);
            $n++;
        }
        $this->counts['分析結果'] = $n;
    }

    // ---------------------------------------------------------------- 詰口・出庫

    private function bottling(): void
    {
        $rows = $this->old->query('SELECT * FROM bottling_entries ORDER BY entry_date, id')->fetchAll();
        $extra = $this->decisions['extra_sample_ml'] ?? [];
        $kindMap = $this->decisions['pseudo_channel_kind'] ?? [];
        $sessions = [];      // キー => [date, sku, lot, items[capId=>count], ml, entries[]]
        $entryToSession = [];
        foreach ($rows as $r) {
            $cap = $this->capByOldId[(int) $r['capacity_id']];
            $count = (int) round((float) $r['bottle_count']);
            if ($count <= 0) {
                continue;
            }
            $key = $r['entry_date'] . '|' . $r['sku_id'] . '|' . $r['lot_id'];
            $sessions[$key] ??= ['date' => $r['entry_date'], 'sku' => (int) $r['sku_id'], 'lot' => (int) $r['lot_id'], 'items' => [], 'ml' => 0, 'entries' => []];
            if ($cap['sample']) {
                $sessions[$key]['ml'] += $cap['ml'] * $count;          // 試飲用（瓶ではない）
            } else {
                $sessions[$key]['items'][$cap['new_id']] = ($sessions[$key]['items'][$cap['new_id']] ?? 0) + $count;
                $newMl = (int) $this->new->value('SELECT ml FROM capacities WHERE id = ?', [$cap['new_id']]);
                $sessions[$key]['ml'] += $newMl * $count;
            }
            $sessions[$key]['ml'] += (int) ($extra[(int) $r['id']] ?? 0);
            $sessions[$key]['entries'][] = (int) $r['id'];
            $entryToSession[(int) $r['id']] = $key;
        }
        $sessionIds = [];
        foreach ($sessions as $key => $s) {
            $sid = $this->new->insert('bottling_sessions', [
                'bottling_date' => $s['date'], 'sku_id' => $this->skuMap[$s['sku']], 'lot_id' => $this->lotMap[$s['lot']],
                'session_ml' => $s['ml'], 'note' => '移行: 旧 bottling_entries ' . implode(',', $s['entries']),
            ]);
            foreach ($s['items'] as $capId => $count) {
                $this->new->insert('bottling_items', ['session_id' => $sid, 'capacity_id' => $capId, 'bottle_count' => $count]);
            }
            $sessionIds[$key] = $sid;
        }
        $this->counts['詰口（セッション）'] = count($sessions);

        // 出庫
        $moves = 0;
        $reclass = [];
        $kinds = ['sales_count' => 'sale', 'tasting' => 'tasting', 'disposal' => 'disposal', 'loss' => 'loss', 'returns' => 'return'];
        foreach ($rows as $r) {
            $cap = $this->capByOldId[(int) $r['capacity_id']];
            foreach ($kinds as $col => $kind) {
                $count = (int) round((float) $r[$col]);
                if ($count <= 0) {
                    continue;
                }
                $ch = $r['channel_id'] === null ? null : $this->channelMap[(int) $r['channel_id']];
                $channelId = $ch['new_id'] ?? null;
                if ($ch !== null && isset($kindMap[$ch['category']])) {
                    if ($kind === 'sale') {
                        $reclass[] = "- 旧id {$r['id']}（{$r['entry_date']}）: 販売 {$count} → {$kindMap[$ch['category']]}（販売先「{$ch['category']}」）" . ($r['note'] ? "　備考: {$r['note']}" : '');
                        $kind = $kindMap[$ch['category']];
                    }
                    $channelId = null;
                }
                if ($cap['sample']) {
                    // 試飲用（70ml 等）の消費 → 試飲用の残りの使用として記録
                    $sessionKey = $this->sampleSessionFor($sessions, (int) $r['sku_id'], (int) $r['lot_id'], (string) $r['entry_date']);
                    if ($sessionKey === null) {
                        $this->unresolved[] = "旧id {$r['id']}: 試飲用の消費に対応する詰口が見つかりません";
                        continue;
                    }
                    $this->new->insert('sample_ml_uses', [
                        'session_id' => $sessionIds[$sessionKey], 'use_date' => $r['entry_date'], 'ml' => $cap['ml'] * $count,
                        'kind' => $kind === 'disposal' ? 'disposal' : 'tasting', 'note' => '移行: 旧id ' . $r['id'] . ($r['note'] ? ' ' . $r['note'] : ''),
                    ]);
                    continue;
                }
                $this->new->insert('product_movements', [
                    'move_date' => $r['entry_date'], 'sku_id' => $this->skuMap[(int) $r['sku_id']], 'capacity_id' => $cap['new_id'],
                    'kind' => $kind, 'count' => $count, 'channel_id' => $channelId,
                    'address' => ($r['address'] ?? '') === '' ? null : $r['address'],
                    'lot_id' => $r['lot_id'] === null ? null : $this->lotMap[(int) $r['lot_id']],
                    'note' => ($r['note'] ?? '') === '' ? null : $r['note'],
                ]);
                $moves++;
            }
        }
        foreach ($this->decisions['sample_uses'] ?? [] as $u) {
            $key = $entryToSession[$u['entry']] ?? null;
            if ($key === null) {
                $this->unresolved[] = "試飲用の使用: 旧id {$u['entry']} の詰口が見つかりません";
                continue;
            }
            $this->new->insert('sample_ml_uses', ['session_id' => $sessionIds[$key], 'use_date' => $u['date'], 'ml' => $u['ml'], 'kind' => $u['kind'], 'note' => $u['note'] ?? null]);
        }
        $this->counts['出庫'] = $moves;
        if ($reclass !== []) {
            $this->report['販売数から振り替えた出庫（CLAUDE.md 8章 #5、仮）'] = $reclass;
        }
    }

    /** 試飲用の消費が、どの詰口の試飲用かを探す（同じSKU・ロットで、その日以前で最も新しい詰口） */
    private function sampleSessionFor(array $sessions, int $sku, int $lot, string $date): ?string
    {
        $best = null;
        foreach ($sessions as $key => $s) {
            if ($s['sku'] === $sku && $s['lot'] === $lot && $s['date'] <= $date && ($best === null || $s['date'] >= $sessions[$best]['date'])) {
                $best = $key;
            }
        }
        return $best;
    }

    // ---------------------------------------------------------------- 酒税・設定

    private function tax(): void
    {
        $forLot = $this->decisions['tax_month_for_lot'] ?? [];
        $paidDates = $this->decisions['tax_paid_date'] ?? [];
        $tax = new LiquorTax();
        $months = [];
        foreach ($this->old->query('SELECT * FROM brewing_lots WHERE tax_amount > 0 OR tax_paid = 1') as $l) {
            $ym = $forLot[$l['lot_code']] ?? null;
            if ($ym === null) {
                $yms = array_column($this->new->all("SELECT DISTINCT substr(bottling_date, 1, 7) AS ym FROM bottling_sessions WHERE lot_id = ?", [$this->lotMap[(int) $l['id']]]), 'ym');
                if (count($yms) !== 1) {
                    $this->unresolved[] = "酒税: ロット {$l['lot_code']} の記録 {$l['tax_amount']}円 をどの月に移すか決まりません（詰口の月: " . (implode('、', $yms) ?: 'なし') . '）';
                    continue;
                }
                $ym = $yms[0];
            }
            $months[$ym]['declared'] = ($months[$ym]['declared'] ?? 0) + (int) round((float) $l['tax_amount']);
            $months[$ym]['paid'] = ($months[$ym]['paid'] ?? true) && (int) $l['tax_paid'] === 1;
            $months[$ym]['lots'][] = $l['lot_code'];
        }
        $lines = [];
        foreach ($months as $ym => $m) {
            $paid = null;
            $note = '移行: 旧ロット ' . implode('、', $m['lots']) . ' の酒税記録';
            if ($m['paid']) {
                $paid = $paidDates[$ym] ?? $tax->dueDate($ym);
                if (!isset($paidDates[$ym])) {
                    $note .= '。納付済みだが納付日が不明のため、納期限を仮置き';
                }
            }
            $this->new->insert('tax_months', ['ym' => $ym, 'declared_amount' => $m['declared'], 'paid_date' => $paid, 'note' => $note]);
            $lines[] = "- {$ym}: 申告額 " . number_format($m['declared']) . '円 ' . ($paid ? "納付日 {$paid}" : '未納') . '（' . implode('、', $m['lots']) . '）';
        }
        $this->report['酒税の記録の移行先（CLAUDE.md 8章 #6）'] = $lines;
    }

    private function settings(): void
    {
        $map = ['liquor_tax_rate_per_kl' => 'tax_rate_per_kl', 'liquor_tax_reduction_percent' => 'reduced_percent'];
        foreach ($this->old->query('SELECT setting_key, setting_value FROM app_settings') as $s) {
            if (isset($map[$s['setting_key']])) {   // admin_password_hash は移さない
                $this->new->run('UPDATE settings SET value = ? WHERE key = ?', [(string) (int) $s['setting_value'], $map[$s['setting_key']]]);
            }
        }
    }

    /** 全液量: 判断ファイルの実測値、なければ詰口の合計で仮置き（CLAUDE.md 7.2） */
    private function lotTotals(): void
    {
        $decided = $this->decisions['lot_total_ml'] ?? [];
        $lines = [];
        foreach ($this->new->all('SELECT l.id, l.lot_code, l.total_ml, SUM(s.session_ml) AS used FROM lots l
            JOIN bottling_sessions s ON s.lot_id = l.id GROUP BY l.id ORDER BY l.lot_code') as $l) {
            if ($l['total_ml'] !== null) {
                continue;
            }
            $ml = $decided[$l['lot_code']] ?? (int) $l['used'];
            $this->new->run('UPDATE lots SET total_ml = ? WHERE id = ?', [$ml, $l['id']]);
            $lines[] = "- {$l['lot_code']}: " . number_format($ml / 1000, 2) . ' L' . (isset($decided[$l['lot_code']]) ? '（実測値）' : '（詰口の合計で仮置き・要確認）');
        }
        $this->report['全液量（CLAUDE.md 8章 #4）'] = $lines;
    }

    // ---------------------------------------------------------------- 報告

    public function markdown(): string
    {
        $md = "# 移行報告\n\n";
        $md .= '- 実行: ' . date('Y-m-d H:i') . "\n- モード: " . ($this->allowUnresolved ? 'リハーサル（未解決は仮置き）' : '本番') . "\n\n";
        $md .= "## 件数\n\n| 対象 | 件数 |\n|---|---|\n";
        foreach ($this->counts as $k => $v) {
            $md .= "| {$k} | {$v} |\n";
        }
        $md .= "\n## 未解決（" . count($this->unresolved) . " 件）\n\n";
        $md .= $this->unresolved === [] ? "なし\n" : implode("\n", array_map(static fn ($u) => "- {$u}", $this->unresolved)) . "\n";
        if ($this->unresolved !== [] && $this->allowUnresolved) {
            $md .= "\n未解決の行は仮のロット `" . self::PLACEHOLDER_LOT . "` に置きました。`config/migration_decisions.php` に答えを書いて、移行をやり直してください。\n";
        }
        foreach ($this->report as $title => $lines) {
            $md .= "\n## {$title}\n\n" . implode("\n", $lines) . "\n";
        }
        return $md;
    }
}
