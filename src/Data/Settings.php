<?php
declare(strict_types=1);

namespace App\Data;

use App\Db\Connection;
use App\Domain\LiquorTax;

final class Settings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private Connection $db)
    {
    }

    public static function make(): self
    {
        return new self(Connection::shared());
    }

    public function int(string $key, int $default = 0): int
    {
        $this->cache ??= array_column($this->db->all('SELECT key, value FROM settings'), 'value', 'key');
        return isset($this->cache[$key]) ? (int) $this->cache[$key] : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
        $this->cache = null;
    }

    public function liquorTax(): LiquorTax
    {
        return new LiquorTax($this->int('tax_rate_per_kl', 100000), $this->int('reduced_percent', 80), $this->int('due_months', 2));
    }

    public function fiscalYearEndMonth(): int
    {
        return $this->int('fiscal_year_end_month', 8);
    }
}
