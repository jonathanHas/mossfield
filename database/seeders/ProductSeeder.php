<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Backfill case_size on existing milk/yoghurt variants if seeder runs against a non-fresh DB.
        ProductVariant::where('size', '1L')->whereNull('case_size')->update(['case_size' => 16]);
        ProductVariant::where('size', '2L')->whereNull('case_size')->update(['case_size' => 8]);
        ProductVariant::whereIn('size', ['250g', '500g'])->whereNull('case_size')->update(['case_size' => 6]);

        // Milk products
        $milk = Product::firstOrCreate(
            [
                'name' => 'Mossfield Organic Milk',
                'type' => 'milk',
            ],
            [
                'description' => 'Fresh organic milk from our grass-fed cows',
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $milk->id,
                'name' => '1L Bottle',
                'size' => '1L',
                'unit' => 'bottle',
            ],
            [
                'weight_kg' => 1.000, // This represents 1L volume for milk
                'base_price' => 2.50,
                'case_size' => 16,
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $milk->id,
                'name' => '2L Bottle',
                'size' => '2L',
                'unit' => 'bottle',
            ],
            [
                'weight_kg' => 2.000, // This represents 2L volume for milk
                'base_price' => 4.50,
                'case_size' => 8,
                'is_active' => true,
            ]
        );

        // Yoghurt products
        $yoghurt = Product::firstOrCreate(
            [
                'name' => 'Mossfield Organic Yoghurt',
                'type' => 'yoghurt',
            ],
            [
                'description' => 'Creamy organic yoghurt made from our fresh milk',
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $yoghurt->id,
                'name' => '250g Tub',
                'size' => '250g',
                'unit' => 'tub',
            ],
            [
                'weight_kg' => 0.250,
                'base_price' => 3.00,
                'case_size' => 6,
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $yoghurt->id,
                'name' => '500g Tub',
                'size' => '500g',
                'unit' => 'tub',
            ],
            [
                'weight_kg' => 0.500,
                'base_price' => 5.50,
                'case_size' => 6,
                'is_active' => true,
            ]
        );

        // Cheese products
        $farmhouseCheese = Product::firstOrCreate(
            [
                'name' => 'Mossfield Farmhouse Cheese',
                'type' => 'cheese',
            ],
            [
                'description' => 'Traditional farmhouse cheese aged to perfection',
                'maturation_days' => 90,
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $farmhouseCheese->id,
                'name' => 'Whole Wheel',
                'size' => 'wheel',
                'unit' => 'wheel',
            ],
            [
                'weight_kg' => 2.500,
                'base_price' => 35.00,
                'is_variable_weight' => true,
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $farmhouseCheese->id,
                'name' => 'Vacuum Pack',
                'size' => 'pack',
                'unit' => 'pack',
            ],
            [
                'weight_kg' => 0.250,
                'base_price' => 4.50,
                'is_variable_weight' => true,
                'is_bulk_weighed' => true,
                'is_active' => true,
            ]
        );

        $garlicBasilCheese = Product::firstOrCreate(
            [
                'name' => 'Mossfield Garlic & Basil Cheese',
                'type' => 'cheese',
            ],
            [
                'description' => 'Aromatic cheese infused with garlic and fresh basil',
                'maturation_days' => 60,
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $garlicBasilCheese->id,
                'name' => 'Whole Wheel',
                'size' => 'wheel',
                'unit' => 'wheel',
            ],
            [
                'weight_kg' => 2.000,
                'base_price' => 40.00,
                'is_variable_weight' => true,
                'is_active' => true,
            ]
        );

        ProductVariant::firstOrCreate(
            [
                'product_id' => $garlicBasilCheese->id,
                'name' => 'Vacuum Pack',
                'size' => 'pack',
                'unit' => 'pack',
            ],
            [
                'weight_kg' => 0.200,
                'base_price' => 5.00,
                'is_variable_weight' => true,
                'is_bulk_weighed' => true,
                'is_active' => true,
            ]
        );
    }
}
