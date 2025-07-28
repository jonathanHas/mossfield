<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BatchSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::with('variants')->get();

        foreach ($products as $product) {
            // Create 3-5 batches per product with different dates
            for ($i = 0; $i < rand(3, 5); $i++) {
                $productionDate = Carbon::now()->subDays(rand(1, 90));
                
                $rawMilkLitres = $this->getRawMilkQuantityByType($product->type);
                $wheelsProduced = null;
                
                // For cheese, always set wheels_produced
                if ($product->type === 'cheese') {
                    $wheelsProduced = rand(2, 8);
                }

                $batch = Batch::create([
                    'product_id' => $product->id,
                    'production_date' => $productionDate,
                    'expiry_date' => $product->type === 'milk' ? $productionDate->copy()->addDays(10) : null,
                    'raw_milk_litres' => $rawMilkLitres,
                    'wheels_produced' => $wheelsProduced,
                    'notes' => $this->getRandomNotes($product->type),
                ]);

                // Create batch items for each variant (skip vacuum packs for cheese)
                foreach ($product->variants as $variant) {
                    // Skip vacuum pack variants for cheese production
                    if ($product->type === 'cheese' && str_contains(strtolower($variant->name), 'vacuum')) {
                        continue;
                    }

                    $quantityProduced = $this->getQuantityByVariant($variant, $product->type, $wheelsProduced);
                    $quantityRemaining = max(0, $quantityProduced - rand(0, $quantityProduced));

                    BatchItem::create([
                        'batch_id' => $batch->id,
                        'product_variant_id' => $variant->id,
                        'quantity_produced' => $quantityProduced,
                        'quantity_remaining' => $quantityRemaining,
                        'unit_weight_kg' => $variant->weight_kg,
                    ]);
                }

                // Update batch status based on remaining stock
                $totalRemaining = $batch->batchItems()->sum('quantity_remaining');
                if ($totalRemaining === 0) {
                    $batch->update(['status' => 'sold_out']);
                } elseif ($batch->isExpired()) {
                    $batch->update(['status' => 'expired']);
                }
            }
        }
    }

    private function getRawMilkQuantityByType(string $type): float
    {
        return match ($type) {
            'milk' => rand(50, 200), // 50-200L raw milk (same as finished product)
            'yoghurt' => rand(40, 120), // 40-120L raw milk to make yoghurt
            'cheese' => rand(60, 150), // 60-150L raw milk to make cheese (lower yield)
            default => rand(50, 100),
        };
    }

    private function getQuantityByVariant(ProductVariant $variant, string $type, ?int $wheelsProduced = null): int
    {
        return match (true) {
            str_contains($variant->name, '1L') => rand(20, 80),
            str_contains($variant->name, '2L') => rand(10, 40),
            str_contains($variant->name, '250g') => rand(30, 120),
            str_contains($variant->name, '500g') => rand(15, 60),
            str_contains($variant->name, 'Wheel') => $wheelsProduced ?? rand(2, 8),
            str_contains($variant->name, 'Vacuum Pack') => rand(20, 100), // Won't be used for initial cheese production
            default => rand(10, 50),
        };
    }

    private function getRandomNotes(string $type): ?string
    {
        $notes = [
            'milk' => [
                null,
                'High cream content batch',
                'Morning milking only',
                'Excellent quality milk',
                'Lower fat content than usual',
            ],
            'yoghurt' => [
                null,
                'Extra creamy texture achieved',
                'Extended fermentation time',
                'Perfect consistency',
                'Slightly tangy flavor profile',
            ],
            'cheese' => [
                null,
                'Excellent aging conditions',
                'Higher moisture content',
                'Perfect wheel formation',
                'Ready for cutting soon',
                'Exceptional flavor development',
            ],
        ];

        return $notes[$type][array_rand($notes[$type])];
    }
}
