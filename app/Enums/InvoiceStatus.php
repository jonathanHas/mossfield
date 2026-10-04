<?php

namespace App\Enums;

/**
 * Lifecycle of an invoice. There is deliberately no "draft": previews are
 * built in memory and consume no number, so anything persisted is real.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Void => 'Void',
        };
    }
}
