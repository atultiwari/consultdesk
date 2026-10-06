<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\PayloadReader;

final class RazorpayCancelHandler implements JobHandler
{
    public function __construct(private readonly RazorpayCheckout $checkout) {}

    public function handle(array $payload): void
    {
        $this->checkout->cancelLink(PayloadReader::int($payload, 'booking_id'));
    }
}
