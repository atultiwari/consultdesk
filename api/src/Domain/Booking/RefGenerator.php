<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

interface RefGenerator
{
    /**
     * A short, human-readable booking reference such as CD-7F3K. Not secret and not guaranteed unique.
     */
    public function next(): string;

    /**
     * A secret, URL-safe token for the public status page.
     */
    public function token(): string;
}
