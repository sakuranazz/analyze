<?php
declare(strict_types=1);

namespace App\Domain;

/** 入口の「要注意」1件（IMPLEMENTATION.md 8.6） */
final class Alert
{
    public function __construct(
        public readonly string $severity,   // danger | warn | info
        public readonly string $title,
        public readonly string $detail,
        public readonly string $link,       // base_path からの相対パス
        public readonly string $linkLabel,
        public readonly string $navKey,     // サイドバーのどの項目に件数を出すか
        public readonly int $count = 1,
    ) {
    }
}
