<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Validation;

/**
 * Field errors shared by an Input and the nested Inputs created from it.
 */
final class ErrorBag
{
    /** @var array<string, string> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->errors;
    }
}
