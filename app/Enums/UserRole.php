<?php

namespace App\Enums;

/**
 * Mossfield user roles.
 *
 * - admin:   user management + everything office can do. Currently just site owner.
 * - office:  full operational access (customers, orders, products, routes, prices).
 * - factory: read-only on packing-sheet data. Write access on pack status lands later.
 * - driver:  deny-all baseline. Narrow grants land with the driver manifest screens.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Office = 'office';
    case Factory = 'factory';
    case Driver = 'driver';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Office => 'Office',
            self::Factory => 'Factory',
            self::Driver => 'Driver',
        };
    }

    /** @return list<self> */
    public static function assignable(): array
    {
        return [self::Admin, self::Office, self::Factory, self::Driver];
    }
}
