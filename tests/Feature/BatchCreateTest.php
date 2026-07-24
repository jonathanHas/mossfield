<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchCreateTest extends TestCase
{
    use RefreshDatabase;

    private function makeMilkProductWithTwoVariants(): array
    {
        $milk = Product::create([
            'name' => 'Test Organic Milk',
            'type' => 'milk',
            'is_active' => true,
        ]);
        $oneLitre = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.0,
            'base_price' => 2.50,
            'is_active' => true,
        ]);
        $twoLitre = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '2L Bottle',
            'size' => '2L',
            'unit' => 'bottle',
            'weight_kg' => 2.0,
            'base_price' => 4.50,
            'is_active' => true,
        ]);

        return [$milk, $oneLitre, $twoLitre];
    }

    public function test_batch_can_be_created_with_only_some_variants_filled(): void
    {
        [$milk, $oneLitre, $twoLitre] = $this->makeMilkProductWithTwoVariants();
        $user = User::factory()->admin()->create();

        // The form posts a row for every variant; untouched rows arrive with an
        // empty quantity — they should be dropped, not rejected.
        $response = $this->actingAs($user)->post('/batches', [
            'product_id' => $milk->id,
            'production_date' => now()->toDateString(),
            'raw_milk_litres' => 500,
            'batch_items' => [
                ['variant_id' => $oneLitre->id, 'quantity_produced' => 500, 'unit_weight_kg' => 1.0],
                ['variant_id' => $twoLitre->id, 'quantity_produced' => '', 'unit_weight_kg' => 2.0],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $batch = Batch::firstOrFail();
        $response->assertRedirect(route('batches.show', $batch));

        $this->assertSame(1, BatchItem::count());
        $item = BatchItem::firstOrFail();
        $this->assertSame($oneLitre->id, $item->product_variant_id);
        $this->assertSame(500, $item->quantity_produced);
        $this->assertSame(500, $item->quantity_remaining);
    }

    public function test_zero_quantity_rows_are_treated_as_not_produced(): void
    {
        [$milk, $oneLitre, $twoLitre] = $this->makeMilkProductWithTwoVariants();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/batches', [
            'product_id' => $milk->id,
            'production_date' => now()->toDateString(),
            'raw_milk_litres' => 200,
            'batch_items' => [
                ['variant_id' => $oneLitre->id, 'quantity_produced' => '0', 'unit_weight_kg' => 1.0],
                ['variant_id' => $twoLitre->id, 'quantity_produced' => 100, 'unit_weight_kg' => 2.0],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, BatchItem::count());
        $this->assertSame($twoLitre->id, BatchItem::firstOrFail()->product_variant_id);
    }

    public function test_all_blank_quantities_are_rejected(): void
    {
        [$milk, $oneLitre, $twoLitre] = $this->makeMilkProductWithTwoVariants();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/batches', [
            'product_id' => $milk->id,
            'production_date' => now()->toDateString(),
            'raw_milk_litres' => 500,
            'batch_items' => [
                ['variant_id' => $oneLitre->id, 'quantity_produced' => '', 'unit_weight_kg' => 1.0],
                ['variant_id' => $twoLitre->id, 'quantity_produced' => '', 'unit_weight_kg' => 2.0],
            ],
        ]);

        $response->assertSessionHasErrors('batch_items');
        $this->assertSame(0, Batch::count());
        $this->assertSame(0, BatchItem::count());
    }

    public function test_negative_quantity_still_fails_validation(): void
    {
        [$milk, $oneLitre] = $this->makeMilkProductWithTwoVariants();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/batches', [
            'product_id' => $milk->id,
            'production_date' => now()->toDateString(),
            'raw_milk_litres' => 500,
            'batch_items' => [
                ['variant_id' => $oneLitre->id, 'quantity_produced' => -5, 'unit_weight_kg' => 1.0],
            ],
        ]);

        $response->assertSessionHasErrors('batch_items.0.quantity_produced');
        $this->assertSame(0, Batch::count());
    }

    public function test_unit_weight_is_taken_from_the_variant_not_the_request(): void
    {
        [$milk, $oneLitre] = $this->makeMilkProductWithTwoVariants();
        $user = User::factory()->admin()->create();

        // A tampered-with unit_weight_kg must be ignored — the stored weight always comes
        // from the variant definition (here 1.0), never from the posted form value.
        $response = $this->actingAs($user)->post('/batches', [
            'product_id' => $milk->id,
            'production_date' => now()->toDateString(),
            'raw_milk_litres' => 500,
            'batch_items' => [
                ['variant_id' => $oneLitre->id, 'quantity_produced' => 500, 'unit_weight_kg' => 999],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $item = BatchItem::firstOrFail();
        $this->assertSame('1.000', $item->unit_weight_kg);
    }

    public function test_cheese_batch_sums_wheels_from_filled_rows_only(): void
    {
        $cheese = Product::create([
            'name' => 'Test Farmhouse Cheese',
            'type' => 'cheese',
            'maturation_days' => 60,
            'is_active' => true,
        ]);
        $wheel = ProductVariant::create([
            'product_id' => $cheese->id,
            'name' => 'Whole Wheel',
            'size' => 'wheel',
            'unit' => 'wheel',
            'weight_kg' => 3.5,
            'base_price' => 12.50,
            'is_variable_weight' => true,
            'is_active' => true,
        ]);
        $pack = ProductVariant::create([
            'product_id' => $cheese->id,
            'name' => 'Vacuum Pack',
            'size' => 'pack',
            'unit' => 'pack',
            'weight_kg' => 0.25,
            'base_price' => 4.00,
            'is_variable_weight' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/batches', [
            'product_id' => $cheese->id,
            'production_date' => now()->toDateString(),
            'raw_milk_litres' => 300,
            'batch_items' => [
                ['variant_id' => $wheel->id, 'quantity_produced' => 12, 'unit_weight_kg' => 3.5],
                ['variant_id' => $pack->id, 'quantity_produced' => '', 'unit_weight_kg' => 0.25],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $batch = Batch::firstOrFail();
        $this->assertSame(12, $batch->wheels_produced);
        $this->assertSame(1, BatchItem::count());
    }
}
