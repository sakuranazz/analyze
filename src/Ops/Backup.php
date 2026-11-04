<?php
declare(strict_types=1);

namespace App\Ops;

use App\Db\Connection;

/**
 * SQLite のバックアップ（CLAUDE.md 6.10、IMPLEMENTATION.md 10章）
 * - VACUUM INTO で、書き込み中でも整合した複製を取る（SQLite 3.27+）
 * - 複製を integrity_check と件数で検証してから、古い世代を消す（失敗が続いても既存の世代は消さない）
 * - 日次は 30 日分、月次（各月の最初に成功した日次）は無期限、ラベル付き（pre-deploy 等）は消さない
 */
final class Backup
{
    private const TABLES = ['processes', 'process_lines', 'lots', 'analysis_results', 'bottling_sessions', 'product_movements'];

    public function __construct(private Connection $db, private string $dir)
    {
    }

    /** @return string 作ったファイル */
    public function run(?string $label = null): string
    {
        $sub = $label === null ? 'daily' : 'manual';
        $name = $label === null ? 'analyze-' . date('Ymd') . '.db' : 'analyze-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9-]/', '', $label) . '.db';
        $path = $this->ensureDir($sub) . '/' . $name;
        if (is_file($path)) {
            $path = substr($path, 0, -3) . '-' . date('His') . '.db';
        }
        $tmp = $path . '.tmp';
        @unlink($tmp);
        $this->db->pdo->exec('VACUUM INTO ' . $this->db->pdo->quote($tmp));
        $this->verify($tmp);
        rename($tmp, $path);
        @chmod($path, 0600);
        if ($label === null) {
            $this->monthly($path);
            $this->rotate();
        }
        return $path;
    }

    /** 複製を開いて、壊れていないか・件数が元と同じかを確かめる */
    public function verify(string $path): void
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException("バックアップが空です: {$path}");
        }
        $copy = Connection::open($path);
        $ok = $copy->value('PRAGMA integrity_check');
        if ($ok !== 'ok') {
            throw new \RuntimeException("バックアップの整合性検査に失敗しました: {$ok}");
        }
        foreach (self::TABLES as $t) {
            $a = (int) $this->db->value("SELECT COUNT(*) FROM {$t}");
            $b = (int) $copy->value("SELECT COUNT(*) FROM {$t}");
            if ($a !== $b) {
                throw new \RuntimeException("バックアップの件数が元と違います（{$t}: {$a} / {$b}）");
            }
        }
    }

    private function monthly(string $daily): void
    {
        $target = $this->ensureDir('monthly') . '/analyze-' . date('Ym') . '.db';
        if (!is_file($target)) {
            copy($daily, $target);
            @chmod($target, 0600);
        }
    }

    private function rotate(): void
    {
        $files = glob($this->dir . '/daily/analyze-*.db') ?: [];
        sort($files);
        $limit = date('Ymd', strtotime('-30 days'));
        foreach ($files as $f) {
            if (preg_match('/analyze-(\d{8})/', basename($f), $m) === 1 && $m[1] < $limit) {
                unlink($f);
            }
        }
    }

    private function ensureDir(string $sub): string
    {
        $d = rtrim($this->dir, '/') . '/' . $sub;
        if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) {
            throw new \RuntimeException("バックアップ先を作れません: {$d}");
        }
        return $d;
    }
}
