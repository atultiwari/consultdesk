<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use RuntimeException;

/**
 * Internal signal from the repository that a generated booking ref already exists.
 */
final class RefCollision extends RuntimeException {}
