<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use RuntimeException;

/**
 * The provider has no active Google connection (never connected, or access was revoked).
 */
final class CalendarDisconnected extends RuntimeException {}
