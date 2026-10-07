<?php

declare(strict_types=1);

namespace ConsultDesk\Install;

use RuntimeException;

/**
 * The installer couldn't finish; $errors says why, per form field.
 */
final class InstallFailed extends RuntimeException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
