<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Milk products
        $milk = Product::create([
            'name' => 'Mossfield Organic Milk',
            'type' => 'milk',
            'description' => 'Fresh organic milk from our grass-fed cows',
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.000,
            'base_price' => 2.50,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '2L Bottle',
            'size' => '2L',
            'unit' => 'bottle',
            'weight_kg' => 2.000,
            'base_price' => 4.50,
            'is_active' => true,
        ]);

        // Yoghurt products
        $yoghurt = Product::create([
            'name' => 'Mossfield Organic Yoghurt',
            'type' => 'yoghurt',
            'description' => 'Creamy organic yoghurt made from our fresh milk',
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $yoghurt->id,
            'name' => '250g Tub',
            'size' => '250g',
            'unit' => 'tub',
            'weight_kg' => 0.250,
            'base_price' => 3.00,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $yoghurt->id,
            'name' => '500g Tub',
            'size' => '500g',
            'unit' => 'tub',
            'weight_kg' => 0.500,
            'base_price' => 5.50,
            'is_active' => true,
        ]);

        // Cheese products
        $farmhouseCheese = Product::create([
            'name' => 'Mossfield Farmhouse Cheese',
            'type' => 'cheese',
            'description' => 'Traditional farmhouse cheese aged to perfection',
            'maturation_days' => 90,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $farmhouseCheese->id,
            'name' => 'Whole Wheel',
            'size' => 'wheel',
            'unit' => 'wheel',
            'weight_kg' => 2.500,
            'base_price' => 35.00,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $farmhouseCheese->id,
            'name' => 'Vacuum Pack',
            'size' => 'pack',
            'unit' => 'pack',
            'weight_kg' => 0.250,
            'base_price' => 4.50,
            'is_active' => true,
        ]);

        $garlicBasilCheese = Product::create([
            'name' => 'Mossfield Garlic & Basil Cheese',
            'type' => 'cheese',
            'description' => 'Aromatic cheese infused with garlic and fresh basil',
            'maturation_days' => 60,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $garlicBasilCheese->id,
            'name' => 'Whole Wheel',
            'size' => 'wheel',
            'unit' => 'wheel',
            'weight_kg' => 2.000,
            'base_price' => 40.00,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $garlicBasilCheese->id,
            'name' => 'Vacuum Pack',
            'size' => 'pack',
            'unit' => 'pack',
            'weight_kg' => 0.200,
            'base_price' => 5.00,
            'is_active' => true,
        ]);
    }
}
