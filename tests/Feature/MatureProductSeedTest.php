<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 4 (2026-09-12 browser pass): "Release to mature" had no destination
 * because the Mature product was never seeded on that database. The seeder is
 * the fix — it must provide exactly what CheeseConversionController::release()
 * looks up, be safe to re-run on a populated DB, and turn the refusal into a
 * working release.
 */
class MatureProductSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_provides_the_release_destination_and_is_idempotent(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $mature = Product::where('name', 'Mossfield Mature Cheese')->where('type', 'cheese')->get();
        $this->assertCount(1, $mature, 'running the seeder twice must not duplicate the product');
        $this->assertTrue($mature->first()->conversion_only, 'Mature is made by conversion only (OPEN_QUESTIONS #5)');
        $this->assertFalse(Product::where('name', 'Mossfield Farmhouse Cheese')->value('conversion_only'));

        $wheel = ProductVariant::where('product_id', $mature->first()->id)
            ->where('name', 'like', '%wheel%')->where('is_active', true)->get();
        $this->assertCount(1, $wheel, 'exactly one active Mature wheel variant — what release() resolves');
        $this->assertSame(1, ProductVariant::where('product_id', $mature->first()->id)
            ->where('name', 'like', '%vacuum%')->count(), 'and a vacuum-pack variant so mature wheels stay cuttable');
    }

    public function test_release_works_against_a_seeded_catalogue(): void
    {
        $this->seed(ProductSeeder::class);

        $farmhouse = Product::where('name', 'Mossfield Farmhouse Cheese')->firstOrFail();
        $farmhouseWheel = ProductVariant::where('product_id', $farmhouse->id)->where('name', 'Whole Wheel')->firstOrFail();

        $batch = Batch::create([
            'product_id' => $farmhouse->id,
            'production_date' => now()->subMonths(6)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        $held = BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $farmhouseWheel->id,
            'quantity_produced' => 5,
            'quantity_remaining' => 5,
            'quantity_maturing' => 2,
            'unit_weight_kg' => 2.5,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('cheese-conversion.release', $held), ['quantity' => 2]);

        $response->assertRedirect(route('cheese-conversion.index'))->assertSessionHasNoErrors();
        $this->followRedirects($response)->assertOk()->assertDontSee('Mature cheese product is not configured');

        $held->refresh();
        $this->assertSame(3, $held->quantity_remaining);
        $this->assertSame(0, $held->quantity_maturing);

        $matureBatch = Batch::where('source_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('Mossfield Mature Cheese', $matureBatch->product->name);
        $this->assertSame(2, (int) $matureBatch->batchItems()->sum('quantity_remaining'));
    }
}
