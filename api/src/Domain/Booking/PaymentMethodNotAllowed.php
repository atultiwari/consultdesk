<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class PaymentMethodNotAllowed extends DomainError
{
    public function __construct(string $message = 'That payment method is not available for this booking.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'payment_method_not_allowed';
    }
}
