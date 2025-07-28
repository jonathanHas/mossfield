<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'name' => 'Dublin Farmers Market',
                'email' => 'orders@dublinfarmersmarket.ie',
                'phone' => '+353 1 234 5678',
                'address' => 'Temple Bar',
                'city' => 'Dublin',
                'postal_code' => 'D02 XY45',
                'credit_limit' => 2000.00,
                'payment_terms' => 'net_14',
                'is_active' => true,
            ],
            [
                'name' => 'Organic Foods Cork',
                'email' => 'purchasing@organicfoodscork.ie',
                'phone' => '+353 21 456 7890',
                'address' => '123 Main Street',
                'city' => 'Cork',
                'postal_code' => 'T12 AB34',
                'credit_limit' => 1500.00,
                'payment_terms' => 'net_30',
                'is_active' => true,
            ],
            [
                'name' => 'Galway Gourmet',
                'email' => 'info@galwaygourmet.ie',
                'phone' => '+353 91 234 5678',
                'address' => '45 Shop Street',
                'city' => 'Galway',
                'postal_code' => 'H91 CD56',
                'credit_limit' => 1000.00,
                'payment_terms' => 'net_7',
                'is_active' => true,
            ],
            [
                'name' => 'Limerick Local Foods',
                'email' => 'orders@limericklocal.ie',
                'phone' => '+353 61 345 6789',
                'address' => '67 O\'Connell Street',
                'city' => 'Limerick',
                'postal_code' => 'V94 EF78',
                'credit_limit' => 800.00,
                'payment_terms' => 'immediate',
                'is_active' => true,
            ],
            [
                'name' => 'Waterford Organic Co-op',
                'email' => 'coop@waterfordorganic.ie',
                'phone' => '+353 51 876 5432',
                'address' => '12 The Quay',
                'city' => 'Waterford',
                'postal_code' => 'X91 GH90',
                'credit_limit' => 1200.00,
                'payment_terms' => 'net_14',
                'is_active' => true,
            ],
            [
                'name' => 'Kilkenny Artisan Foods',
                'email' => 'hello@kilkennyartisan.ie',
                'phone' => '+353 56 123 4567',
                'address' => '89 High Street',
                'city' => 'Kilkenny',
                'postal_code' => 'R95 IJ12',
                'credit_limit' => 600.00,
                'payment_terms' => 'net_7',
                'is_active' => true,
            ],
            [
                'name' => 'Donegal Mountain Cheese',
                'email' => 'orders@donegalmountain.ie',
                'phone' => '+353 74 987 6543',
                'address' => '34 Main Street',
                'city' => 'Letterkenny',
                'postal_code' => 'F92 KL34',
                'credit_limit' => 500.00,
                'payment_terms' => 'immediate',
                'is_active' => true,
            ],
            [
                'name' => 'Kerry Organic Retailers',
                'email' => 'purchasing@kerryorganic.ie',
                'phone' => '+353 66 234 5678',
                'address' => '56 Main Street',
                'city' => 'Killarney',
                'postal_code' => 'V93 MN56',
                'credit_limit' => 900.00,
                'payment_terms' => 'net_14',
                'is_active' => true,
            ],
        ];

        foreach ($customers as $customerData) {
            Customer::create($customerData);
        }
    }
}