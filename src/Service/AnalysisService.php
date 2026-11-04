<?php
declare(strict_types=1);

namespace App\Service;

use App\Data\Analysis;
use App\Db\Connection;
use App\Domain\DateUtil;
use App\Domain\Qty;
use App\Domain\ValidationError;

/** 分析結果の登録・修正・削除。測定値は金額ではないので REAL で持つ */
final class AnalysisService
{
    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * @param array<string, mixed> $post 画面の入力
     * @return array<string, mixed> 保存する行
     */
    public function parse(array $post): array
    {
        $e = [];
        $row = [];
        try {
            $row['entry_date'] = DateUtil::parse((string) ($post['entry_date'] ?? ''));
        } catch (\InvalidArgumentException $ex) {
            $e['entry_date'] = $ex->getMessage();
        }
        $lot = (int) ($post['lot_id'] ?? 0);
        if ($lot <= 0 || $this->db->value('SELECT 1 FROM lots WHERE id = ?', [$lot]) === null) {
            $e['lot_id'] = 'ロットを選んでください';
        }
        $row['lot_id'] = $lot;
        $any = false;
        foreach (Analysis::FIELDS as $f => [$label]) {
            $raw = Qty::normalize((string) ($post[$f] ?? ''));
            if ($raw === '') {
                $row[$f] = null;
                continue;
            }
            if (preg_match('/^-?(\d+|\d*\.\d+)$/', $raw) !== 1) {
                $e[$f] = "{$label}は数字で入力してください";
                continue;
            }
            // 小数第1位まで（「1.50」のように後ろが0だけなら許す）
            if (preg_match('/\.\d(0*)$|^-?\d+$/', $raw) !== 1) {
                $e[$f] = "{$label}は小数第1位まで（例: 1.5）で入力してください";
                continue;
            }
            $row[$f] = (float) $raw;
            $any = true;
        }
        $note = trim((string) ($post['note'] ?? ''));
        $row['note'] = $note === '' ? null : $note;
        if (!$any && $row['note'] === null && $e === []) {
            $e['amino_acid'] = '数値か備考を1つ以上入力してください';
        }
        if ($e !== []) {
            throw new ValidationError($e);
        }
        return $row;
    }

    /** @param array<string, mixed> $post */
    public function save(?int $id, array $post): int
    {
        $row = $this->parse($post);
        $a = new Analysis($this->db);
        return $this->db->transaction(function () use ($id, $row, $a): int {
            if ($id === null) {
                return $a->insert($row);
            }
            $a->update($id, $row);
            return $id;
        });
    }

    public function delete(int $id): void
    {
        $this->db->transaction(fn () => (new Analysis($this->db))->delete($id));
    }

    /**
     * 前回値と大きく違う項目（±50%超）。保存は止めない（DESIGN.md 4.2）
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public function outliers(array $row, ?int $exceptId = null): array
    {
        $prev = (new Analysis($this->db))->previousValues((int) $row['lot_id'], $exceptId);
        $out = [];
        foreach (Analysis::FIELDS as $f => [$label]) {
            if (!isset($row[$f], $prev[$f])) {
                continue;
            }
            $p = $prev[$f]['value'];
            if (abs($p) >= 0.1 && abs($row[$f] - $p) > abs($p) * 0.5) {
                $out[$f] = "前回 {$p}（{$prev[$f]['date']}）と大きく違います";
            }
        }
        return $out;
    }
}
