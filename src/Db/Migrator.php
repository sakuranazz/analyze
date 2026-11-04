<?php
declare(strict_types=1);

namespace App\Db;

/**
 * migrations/ の連番SQLを順に適用する（IMPLEMENTATION.md 5.2）。
 * - 1ファイル＝1トランザクション
 * - 適用済みファイルの改変はチェックサムで検出して止まる（改行コードは正規化）
 * - 先頭行に "-- migrate:rebuild" があるファイルは、外部キー検査を外して適用し、最後に foreign_key_check で確かめる
 */
final class Migrator
{
    public function __construct(private Connection $db, private string $dir)
    {
    }

    /** @return list<string> 適用したバージョン */
    public function migrate(): array
    {
        $this->db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL)');
        $applied = [];
        foreach ($this->db->all('SELECT version, checksum FROM schema_migrations') as $r) {
            $applied[(string) $r['version']] = (string) $r['checksum'];
        }
        $done = [];
        foreach ($this->files() as $version => $path) {
            $sql = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
            $sum = hash('sha256', $sql);
            if (isset($applied[$version])) {
                if ($applied[$version] !== $sum) {
                    throw new \RuntimeException("適用済みのマイグレーション {$version} が書き換えられています。新しい番号で足してください。");
                }
                continue;
            }
            $rebuild = str_starts_with(ltrim($sql), '-- migrate:rebuild');
            if ($rebuild) {
                $this->db->pdo->exec('PRAGMA foreign_keys = OFF');
            }
            try {
                $this->db->transaction(function () use ($sql, $version, $sum, $rebuild): void {
                    $this->db->pdo->exec($sql);
                    if ($rebuild) {
                        $bad = $this->db->all('PRAGMA foreign_key_check');
                        if ($bad !== []) {
                            throw new \RuntimeException("{$version}: 外部キーの不整合が " . count($bad) . ' 件あります');
                        }
                    }
                    $this->db->run(
                        'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, ?)',
                        [$version, $sum, date('c')]
                    );
                });
            } finally {
                if ($rebuild) {
                    $this->db->pdo->exec('PRAGMA foreign_keys = ON');
                }
            }
            $done[] = $version;
        }
        return $done;
    }

    /** @return array<string, string> version => path */
    private function files(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $path) {
            $name = basename($path, '.sql');
            if (preg_match('/^\d{4}_[a-z0-9_]+$/', $name) !== 1) {
                throw new \RuntimeException("マイグレーションのファイル名が不正です: {$name}");
            }
            $out[$name] = $path;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
