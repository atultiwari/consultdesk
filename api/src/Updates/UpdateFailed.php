<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

use RuntimeException;

/**
 * An update couldn't be checked or applied; the message is shown to the owner.
 */
final class UpdateFailed extends RuntimeException {}
