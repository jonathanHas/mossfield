<?php

namespace App\Enums;

enum InvoiceType: string
{
    case PerOrder = 'per_order';
    case Consolidated = 'consolidated';

    public function label(): string
    {
        return match ($this) {
            self::PerOrder => 'Per order',
            self::Consolidated => 'Consolidated',
        };
    }
}
