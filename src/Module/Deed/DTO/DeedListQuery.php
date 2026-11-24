<?php

declare(strict_types=1);

namespace App\Module\Deed\DTO;

final class DeedListQuery
{
    public const MAX_WORDS = 10;
    public const MAX_WORD_LENGTH = 100;

    /**
     * @param string[] $words
     * @param array<string, string> $errors
     */
    private function __construct(
        public readonly int $page,
        public readonly array $words,
        private readonly array $errors,
    ) {}

    public static function fromQueryParams(?string $page, ?string $search): self
    {
        $errors = [];
        $pageNumber = 1;

        if ($page !== null) {
            if (preg_match('/^[1-9]\d{0,8}$/', $page) === 1) {
                $pageNumber = (int) $page;
            } else {
                $errors['page'] = 'Page must be a positive integer';
            }
        }

        return new self($pageNumber, self::parseWords($search), $errors);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[]
     */
    private static function parseWords(?string $search): array
    {
        if ($search === null) {
            return [];
        }

        $parts = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        return array_map(
            static fn (string $word): string => mb_substr($word, 0, self::MAX_WORD_LENGTH),
            array_slice($parts, 0, self::MAX_WORDS)
        );
    }
}
