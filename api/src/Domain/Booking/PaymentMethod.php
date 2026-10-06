<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

enum PaymentMethod: string
{
    case Upi = 'upi';
    case RazorpayLink = 'razorpay_link';
    case Free = 'free';
}
