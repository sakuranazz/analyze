<?php
declare(strict_types=1);

namespace App\Db;

use App\Config;
use PDO;
use PDOStatement;

/**
 * SQLite 接続。トランザクションは transaction() だけで扱う。
 * PDO::beginTransaction() は遅延の BEGIN になり、読んでから書く処理で SQLITE_BUSY になりうるため使わない（IMPLEMENTATION.md 5.1）。
 */
final class Connection
{
    private static ?self $shared = null;
    private int $depth = 0;

    public function __construct(public readonly PDO $pdo)
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public static function open(string $path): self
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = DELETE');
        }
        return new self($pdo);
    }

    public static function shared(): self
    {
        return self::$shared ??= self::open((string) Config::get('db_path'));
    }

    public static function setShared(?self $db): void
    {
        self::$shared = $db;
    }

    /**
     * 1操作＝1トランザクション。入れ子は SAVEPOINT。
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $level = $this->depth;
        $this->pdo->exec($level === 0 ? 'BEGIN IMMEDIATE' : "SAVEPOINT sp{$level}");
        $this->depth++;
        try {
            $result = $fn();
            $this->depth--;
            $this->pdo->exec($level === 0 ? 'COMMIT' : "RELEASE sp{$level}");
            return $result;
        } catch (\Throwable $e) {
            $this->depth--;
            if ($level === 0) {
                $this->pdo->exec('ROLLBACK');
            } else {
                $this->pdo->exec("ROLLBACK TO sp{$level}");
                $this->pdo->exec("RELEASE sp{$level}");
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v), is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($name, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->run($sql, $row);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $row */
    public function update(string $table, int $id, array $row): void
    {
        $sets = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($row)));
        $row['__id'] = $id;
        $this->run("UPDATE {$table} SET {$sets}, updated_at = strftime('%Y-%m-%dT%H:%M:%S', 'now', 'localtime') WHERE id = :__id", $row);
    }
}
