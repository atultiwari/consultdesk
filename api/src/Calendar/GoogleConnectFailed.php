<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use RuntimeException;

/**
 * Connecting a Google account did not work. The message is safe to show to the provider.
 */
final class GoogleConnectFailed extends RuntimeException {}
