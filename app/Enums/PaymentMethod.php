<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::Card => 'Card',
            self::Other => 'Other',
        };
    }

    /** @return list<self> */
    public static function selectable(): array
    {
        return [self::BankTransfer, self::Cash, self::Cheque, self::Card, self::Other];
    }
}
