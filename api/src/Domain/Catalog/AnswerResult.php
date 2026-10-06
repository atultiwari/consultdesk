<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

final class AnswerResult
{
    /**
     * @param array<string, mixed> $answers
     * @param array<string, string> $errors question id => message
     */
    public function __construct(
        public readonly array $answers,
        public readonly array $errors,
    ) {}
}
