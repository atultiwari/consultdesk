<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Validation;

use ConsultDesk\Domain\DomainError;

final class ValidationFailed extends DomainError
{
    /**
     * @param array<string, string> $fields field path => message
     */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Please check the highlighted fields.');
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }
}
