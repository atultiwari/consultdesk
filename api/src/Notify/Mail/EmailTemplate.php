<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

enum EmailTemplate: string
{
    case CustomerPaymentDue = 'customer.payment_due';
    case CustomerRequestReceived = 'customer.request_received';
    case CustomerPaymentReceived = 'customer.payment_received';
    case CustomerConfirmed = 'customer.confirmed';
    case CustomerRejected = 'customer.rejected';
    case CustomerCancelled = 'customer.cancelled';
    case CustomerExpired = 'customer.expired';
    case StaffApprovalNeeded = 'staff.approval_needed';
    case StaffVerifyPayment = 'staff.verify_payment';
    case StaffConfirmed = 'staff.confirmed';

    public function isForCustomer(): bool
    {
        return str_starts_with($this->value, 'customer.');
    }
}
