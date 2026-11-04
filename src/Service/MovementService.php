<?php
declare(strict_types=1);

namespace App\Service;

use App\Db\Connection;
use App\Domain\StockCalculator;
use App\Domain\ValidationError;

/** 出庫（販売・試飲・廃棄・遺失・返品）と、試飲用の残りの使用・処分 */
final class MovementService
{
    public const KINDS = ['sale' => '販売', 'tasting' => '試飲', 'disposal' => '廃棄', 'loss' => '遺失', 'return' => '返品'];

    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    /**
     * @param array{move_date:string, sku_id:int, capacity_id:int, kind:string, count:int, channel_id:?int,
     *              address:?string, lot_id:?int, note:?string} $in
     * @return list<string> 警告
     */
    public function add(array $in): array
    {
        $errors = [];
        if (!isset(self::KINDS[$in['kind']])) {
            $errors['kind'] = '種別を選んでください';
        }
        if ($in['count'] <= 0) {
            $errors['count'] = '本数は1以上です';
        }
        if ($in['kind'] === 'sale' && $in['channel_id'] === null) {
            $errors['channel_id'] = '販売先を選んでください（年度別サマリの区分に使います）';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        $warnings = [];
        if (StockCalculator::movementSign($in['kind']) < 0) {
            $stock = $this->stock($in['sku_id'], $in['capacity_id']);
            if ($stock - $in['count'] < 0) {
                $warnings[] = "在庫（{$stock}本）より多く出庫します。製品在庫がマイナスになります";
            }
        }
        $this->db->transaction(fn () => $this->db->insert('product_movements', $in));
        return $warnings;
    }

    public function delete(int $id): void
    {
        $this->db->transaction(fn () => $this->db->run('DELETE FROM product_movements WHERE id = ?', [$id]));
    }

    public function stock(int $skuId, int $capacityId): int
    {
        $bottled = (int) $this->db->value('SELECT COALESCE(SUM(i.bottle_count), 0) FROM bottling_items i JOIN bottling_sessions s ON s.id = i.session_id
            WHERE s.sku_id = ? AND i.capacity_id = ?', [$skuId, $capacityId]);
        $moves = (int) $this->db->value("SELECT COALESCE(SUM(CASE WHEN kind = 'return' THEN count ELSE -count END), 0)
            FROM product_movements WHERE sku_id = ? AND capacity_id = ?", [$skuId, $capacityId]);
        return $bottled + $moves;
    }

    /** 試飲用の残りを試飲で使う・処分する（CLAUDE.md 6.2） */
    public function useSample(int $sessionId, string $date, int $ml, string $kind, ?string $note = null): void
    {
        if (!in_array($kind, ['tasting', 'disposal'], true)) {
            throw ValidationError::one('kind', '種別は試飲か処分です');
        }
        $this->db->transaction(function () use ($sessionId, $date, $ml, $kind, $note): void {
            $left = $this->db->value('SELECT sample_ml - sample_used_ml FROM v_bottling_sessions WHERE id = ?', [$sessionId]);
            if ($left === null) {
                throw ValidationError::one('session_id', '詰口が見つかりません');
            }
            if ($ml <= 0 || $ml > (int) $left) {
                throw ValidationError::one('ml', "試飲用の残り（{$left}ml）以内で入力してください");
            }
            $this->db->insert('sample_ml_uses', ['session_id' => $sessionId, 'use_date' => $date, 'ml' => $ml, 'kind' => $kind, 'note' => $note]);
        });
    }

    public function deleteSampleUse(int $id): void
    {
        $this->db->transaction(fn () => $this->db->run('DELETE FROM sample_ml_uses WHERE id = ?', [$id]));
    }
}
