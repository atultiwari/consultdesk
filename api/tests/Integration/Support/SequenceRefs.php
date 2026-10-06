<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Support;

use ConsultDesk\Domain\Booking\RandomRefGenerator;
use ConsultDesk\Domain\Booking\RefGenerator;

/**
 * Hands out the given refs in order, then keeps repeating the last one.
 */
final class SequenceRefs implements RefGenerator
{
    /**
     * @param non-empty-list<string> $refs
     */
    public function __construct(private array $refs) {}

    public function next(): string
    {
        return count($this->refs) > 1 ? (string) array_shift($this->refs) : $this->refs[0];
    }

    public function token(): string
    {
        return (new RandomRefGenerator())->token();
    }
}
