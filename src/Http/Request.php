<?php
declare(strict_types=1);

namespace App\Http;

use App\Domain\DateUtil;
use App\Domain\Qty;
use App\Domain\ValidationError;

/** 入力の取り出し。型付きのアクセサで、全角→半角・カンマ除去などをここに集める */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        private array $query,
        private array $post,
        private array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $k, 5)))] = (string) $v;
            }
        }
        return new self(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $_GET, $_POST, $headers);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** インライン編集の fetch から来たか（行の HTML 断片を返す） */
    public function wantsFragment(): bool
    {
        return ($this->headers['x-fragment'] ?? '') === '1';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post) || array_key_exists($key, $this->query);
    }

    public function raw(string $key): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? null;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->raw($key);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function strOrNull(string $key): ?string
    {
        $v = $this->str($key);
        return $v === '' ? null : $v;
    }

    /** 許可リストから選ぶ（タブ名・並べ替えキーなど） @param list<string> $allowed */
    public function choice(string $key, array $allowed, string $default): string
    {
        $v = $this->str($key);
        return in_array($v, $allowed, true) ? $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = Qty::normalize($this->str($key));
        return preg_match('/^-?\d+$/', $v) === 1 ? (int) $v : $default;
    }

    public function intOrNull(string $key): ?int
    {
        $v = Qty::normalize($this->str($key));
        return preg_match('/^-?\d+$/', $v) === 1 ? (int) $v : null;
    }

    /** 日付（YYYY-MM-DD）。不正なら ValidationError */
    public function date(string $key, string $label = '日付'): string
    {
        try {
            return DateUtil::parse($this->str($key), $label);
        } catch (\InvalidArgumentException $e) {
            throw ValidationError::one($key, $e->getMessage());
        }
    }

    public function dateOrNull(string $key, string $label = '日付'): ?string
    {
        return $this->str($key) === '' ? null : $this->date($key, $label);
    }

    /** スケール付き整数（数量は 1000、単価は 1,000,000） */
    public function scaled(string $key, int $scale, string $label): int
    {
        try {
            return Qty::parse($this->str($key), $scale, $label);
        } catch (\InvalidArgumentException $e) {
            throw ValidationError::one($key, $e->getMessage());
        }
    }

    public function scaledOrNull(string $key, int $scale, string $label): ?int
    {
        return $this->str($key) === '' ? null : $this->scaled($key, $scale, $label);
    }

    /** @return array<int|string, mixed> */
    public function arr(string $key): array
    {
        $v = $this->raw($key);
        return is_array($v) ? $v : [];
    }

    /** @return array<string, mixed> */
    public function postAll(): array
    {
        return $this->post;
    }
}
