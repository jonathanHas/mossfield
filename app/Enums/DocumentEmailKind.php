<?php

namespace App\Enums;

enum DocumentEmailKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Statement = 'statement';
    case Reminder = 'reminder';
    case Docket = 'docket';
    case DispatchConfirmation = 'dispatch_confirmation';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::CreditNote => 'Credit note',
            self::Statement => 'Statement',
            self::Reminder => 'Payment reminder',
            self::Docket => 'Dispatch docket',
            self::DispatchConfirmation => 'Dispatch confirmation',
        };
    }
}
