<?php

namespace App\Enums;

/**
 * How the customer wants their invoices delivered. "print" and "none" keep
 * their invoices out of the "not yet emailed" queue — they were never going
 * to be emailed.
 */
enum InvoiceDelivery: string
{
    case Email = 'email';
    case Print = 'print';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Print => 'Print / post',
            self::None => 'No invoice sent',
        };
    }
}
