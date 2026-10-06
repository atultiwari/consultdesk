<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use RuntimeException;

/**
 * Razorpay refused a request or could not be reached. The message is safe to show staff.
 */
final class RazorpayError extends RuntimeException {}
