<?php

namespace App\Enums;

/**
 * How a customer is billed: one invoice per order, or one consolidated invoice
 * covering a week/month of deliveries.
 */
enum InvoicingMode: string
{
    case PerOrder = 'per_order';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::PerOrder => 'Per order',
            self::Weekly => 'Weekly consolidated',
            self::Monthly => 'Monthly consolidated',
        };
    }

    public function isConsolidated(): bool
    {
        return $this !== self::PerOrder;
    }
}
