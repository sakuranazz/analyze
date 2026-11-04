<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 入力の誤り。項目名 → 日本語メッセージ。画面が欄の直下に出す（ログには出さない）。
 */
final class ValidationError extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(private array $fields)
    {
        parent::__construct(implode(' / ', $fields));
    }

    public static function one(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
