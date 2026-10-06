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

    public function test_a_refused_batch_hands_back_the_quantities_and_the_bad_expiry(): void
    {
        // E2E F-08: after a refusal the variant rows came back blank and the
        // refused expiry was overwritten by the shelf-life auto-fill.
        [$milk, $oneLitre] = $this->makeMilkProductWithTwoVariants();
        $user = User::factory()->admin()->create();
        $production = now()->toDateString();
        $badExpiry = now()->subDays(3)->toDateString();

        $response = $this->actingAs($user)
            ->from(route('batches.create'))
            ->post('/batches', [
                'product_id' => $milk->id,
                'production_date' => $production,
                'expiry_date' => $badExpiry,
                'raw_milk_litres' => 10,
                'batch_items' => [
                    ['variant_id' => $oneLitre->id, 'quantity_produced' => '10'],
                ],
            ]);

        $response->assertSessionHasErrors('expiry_date');
        $this->assertSame(0, Batch::count());

        $page = $this->followRedirects($response)->assertOk()->getContent();

        // The old rows are embedded for the script to seed the rebuilt inputs…
        $this->assertMatchesRegularExpression('/"variant_id":"?'.$oneLitre->id.'"?,"quantity_produced":"10"/', $page);
        // …and the refused expiry is in the field, with the auto-fill told to leave it.
        $this->assertMatchesRegularExpression('/<input[^>]*id="expiry_date"[^>]*value="'.$badExpiry.'"/s', $page);
        $this->assertStringContainsString('if (initialising && expiryDateInput.value)', $page);
    }

    public function test_a_batch_without_expiry_says_it_stays_sellable(): void
    {
        $yoghurt = Product::create(['name' => 'Test Yoghurt', 'type' => 'yoghurt', 'is_active' => true]);
        $batch = Batch::create([
            'product_id' => $yoghurt->id, 'production_date' => now()->subDay(),
            'expiry_date' => null, 'raw_milk_litres' => 50, 'status' => 'active',
        ]);

        $this->actingAs(User::factory()->admin()->create())->get(route('batches.show', $batch))
            ->assertOk()
            ->assertSee('None — stays sellable until sold');
    }

    public function test_the_create_form_carries_the_no_shelf_life_warning(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('batches.create'))
            ->assertOk()
            ->assertSee('has no shelf life set', false)
            ->assertSee('data-default-text=', false);
    }

    /** A cheese product with a wheel (size = unit = "wheel") and a pack variant. */
    private function cheese(int $maturationDays = 60): array
    {
        $cheese = Product::create(['name' => 'Test Farmhouse', 'type' => 'cheese', 'maturation_days' => $maturationDays, 'is_active' => true]);
        $wheel = ProductVariant::create([
            'product_id' => $cheese->id, 'name' => 'Whole Wheel', 'size' => 'wheel', 'unit' => 'wheel',
            'weight_kg' => 5.0, 'base_price' => 16.00, 'is_active' => true,
        ]);
        $pack = ProductVariant::create([
            'product_id' => $cheese->id, 'name' => 'Vacuum Pack', 'size' => '250g', 'unit' => 'pack',
            'weight_kg' => 0.25, 'base_price' => 4.00, 'is_active' => true,
        ]);

        return [$cheese, $wheel, $pack];
    }

    private function cheeseBatch(Product $cheese, array $items, array $attrs = []): Batch
    {
        $batch = Batch::create(array_merge([
            'product_id' => $cheese->id, 'production_date' => today(), 'raw_milk_litres' => 100, 'status' => 'active',
        ], $attrs));
        foreach ($items as [$variant, $produced, $remaining]) {
            BatchItem::create([
                'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
                'quantity_produced' => $produced, 'quantity_remaining' => $remaining, 'unit_weight_kg' => $variant->weight_kg,
            ]);
        }

        return $batch;
    }

    public function test_the_batch_page_reads_cleanly(): void
    {
        [$cheese, $wheel, $pack] = $this->cheese(60);
        $batch = $this->cheeseBatch($cheese, [[$wheel, 10, 10], [$pack, 20, 18]]);
        $mature = $this->cheeseBatch($cheese, [[$wheel, 2, 2]], [
            'raw_milk_litres' => 0, 'source_batch_id' => $batch->id, 'production_date' => today()->subDays(10),
        ]);

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('batches.show', $batch))
            ->assertOk()
            ->assertSee('10 wheels · 18 packs')
            ->assertSee('in 60 days')
            ->assertDontSee('2 months from now')
            ->getContent();
        $this->assertStringNotContainsString('wheel wheel', $html);
        $this->assertMatchesRegularExpression('/>\s*wheel\s*</', $html);

        $this->actingAs(User::factory()->admin()->create())->get(route('batches.show', $mature))
            ->assertOk()
            ->assertSeeInOrder(['Raw milk used', '—'])
            ->assertDontSee('0.00 L');
    }

    public function test_the_index_hides_delete_on_a_batch_that_cannot_be_deleted_without_extra_queries(): void
    {
        [$cheese, $wheel] = $this->cheese(0);
        $admin = User::factory()->admin()->create();
        $fresh = $this->cheeseBatch($cheese, [[$wheel, 5, 5]], ['production_date' => today()->subDays(3)]);
        $held = $this->cheeseBatch($cheese, [[$wheel, 5, 5]], ['production_date' => today()->subDays(4)]);
        $this->allocateOne($held);

        $html = $this->actingAs($admin)->get(route('batches.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm="Batch '.$fresh->batch_code." and its items are removed. This can't be undone.\"", $html);
        $this->assertStringNotContainsString('data-confirm="Delete batch '.$held->batch_code, $html);
        $this->assertStringContainsString("Can't delete — 1 record depends on it", $html);
        $this->assertSame(1, substr_count($html, 'data-delete-blocked'));

        // The deletion counts ride the one batch_items eager-load however many
        // cards there are — no per-card count query, no per-card stock SUM.
        // (Total counts: see test_the_batch_screens_cost_the_same_queries_at_any_size.)
        $queries = function () use ($admin) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->actingAs($admin)->get(route('batches.index'))->assertOk();
            $log = array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query');
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return collect($log);
        };
        $countQueries = fn ($log) => $log->filter(fn ($q) => str_contains($q, 'target_conversion_logs_count'))->count();
        $stockSums = fn ($log) => $log->filter(fn ($q) => str_contains($q, 'sum(`quantity_remaining`)'))->count();

        $before = $queries();
        $this->cheeseBatch($cheese, [[$wheel, 5, 5]], ['production_date' => today()->subDays(5)]);
        $this->allocateOne($this->cheeseBatch($cheese, [[$wheel, 5, 5]], ['production_date' => today()->subDays(6)]));
        $after = $queries();

        $this->assertSame(1, $countQueries($before));
        $this->assertSame(1, $countQueries($after));
        $this->assertSame(0, $stockSums($after));
    }

    /**
     * The three wheel screens cost the same number of queries at 2 and at 4
     * batches. wheelStates() used to fall back to a live SUM whenever an
     * eager-loaded sum was NULL (no conversions / no allocations) — an N+1.
     */
    public function test_the_batch_screens_cost_the_same_queries_at_any_size(): void
    {
        [$cheese, $wheel, $pack] = $this->cheese(0);
        $admin = User::factory()->admin()->create();
        // One plain batch (no allocations, no conversions: the NULL case) and one reserved.
        $plain = $this->cheeseBatch($cheese, [[$wheel, 5, 5], [$pack, 10, 10]], ['production_date' => today()->subDays(3)]);
        $this->allocateOne($this->cheeseBatch($cheese, [[$wheel, 5, 5]], ['production_date' => today()->subDays(4)]));
        // A write-off too (cycle 7): its sum rides the same eager-load.
        app(\App\Services\StockWriteOffService::class)->record(
            $plain->batchItems()->first(), \App\Models\WriteOffReason::where('code', 'spoiled')->firstOrFail(),
            1, null, null, today(), $admin,
        );

        $count = function (string $url) use ($admin): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->actingAs($admin)->get($url)->assertOk();
            $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $n;
        };
        $urls = [route('batches.index'), route('cheese-cutting.index'), route('cheese-conversion.index')];
        $before = array_map($count, $urls);

        $this->cheeseBatch($cheese, [[$wheel, 5, 5], [$pack, 10, 10]], ['production_date' => today()->subDays(5)]);
        $this->allocateOne($this->cheeseBatch($cheese, [[$wheel, 5, 5]], ['production_date' => today()->subDays(6)]));
        $after = array_map($count, $urls);

        $this->assertSame($before, $after, '/batches, /cheese-cutting, /cheese-conversion');
    }

    private function allocateOne(Batch $batch): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'Shop '.uniqid(), 'email' => uniqid().'@example.ie',
            'address' => 'Main St', 'city' => 'Bray', 'postal_code' => 'A98', 'is_active' => true,
        ]);
        $order = \App\Models\Order::create([
            'customer_id' => $customer->id, 'order_date' => today(), 'status' => 'confirmed',
            'payment_status' => 'pending', 'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $item = $batch->batchItems()->first();
        $line = $order->orderItems()->create(['product_variant_id' => $item->product_variant_id, 'quantity_ordered' => 1, 'unit_price' => 1]);
        $this->assertNotNull($line->allocateFromBatchItem($item, 1));
    }

    // ── Policy batch (OPEN_QUESTIONS #5) ─────────────────────────────

    private function postBatch(Product $product, array $items, float $raw = 100, bool $confirm = false)
    {
        return $this->actingAs(User::factory()->admin()->create())->post(route('batches.store'), array_filter([
            'product_id' => $product->id,
            'production_date' => today()->toDateString(),
            'raw_milk_litres' => $raw,
            'batch_items' => $items,
            'confirm_large' => $confirm ? '1' : null,
        ], fn ($v) => $v !== null));
    }

    public function test_a_conversion_only_product_gets_no_production_batch(): void
    {
        [$cheese, $wheel] = $this->cheese();
        $cheese->update(['name' => 'Mossfield Mature Cheese', 'conversion_only' => true]);
        [$farm] = $this->cheese();

        $this->actingAs(User::factory()->admin()->create())->get(route('batches.create'))
            ->assertOk()
            ->assertDontSee('Mossfield Mature Cheese (Cheese)')
            ->assertSee($farm->name.' (Cheese)');

        $this->postBatch($cheese, [['variant_id' => $wheel->id, 'quantity_produced' => 4]])
            ->assertSessionHasErrors(['product_id' => 'Mossfield Mature Cheese is only made by releasing matured wheels — use Mature Conversion.']);
        $this->assertSame(0, Batch::where('product_id', $cheese->id)->count());
    }

    public function test_a_batch_above_the_ceiling_needs_an_explicit_yes(): void
    {
        [$cheese, $wheel] = $this->cheese();

        $this->postBatch($cheese, [['variant_id' => $wheel->id, 'quantity_produced' => 3000]], 30000)
            ->assertSessionHas('warning')
            ->assertSessionHasErrors(['batch_items.0.quantity_produced' => "3,000 × Whole Wheel — the usual ceiling is 200. Tick 'Yes, this batch really is that big' to save it."]);
        $this->assertSame(0, Batch::count());

        // The form, re-rendered with that error, offers the box.
        $this->actingAs(User::factory()->admin()->create())->get(route('batches.create'))
            ->assertSee('data-confirm-large', false)
            ->assertSee('Yes, this batch really is that big');

        $this->postBatch($cheese, [['variant_id' => $wheel->id, 'quantity_produced' => 3000]], 30000, confirm: true)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '(large batch confirmed)'));
        $this->assertSame(3000, (int) BatchItem::sole()->quantity_produced);
    }

    public function test_at_or_under_the_ceiling_saves_without_asking(): void
    {
        [$cheese, $wheel] = $this->cheese();
        $this->postBatch($cheese, [['variant_id' => $wheel->id, 'quantity_produced' => 200]], 2000)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        [, $oneLitre] = $this->makeMilkProductWithTwoVariants();
        $this->postBatch($oneLitre->product, [['variant_id' => $oneLitre->id, 'quantity_produced' => 4999]], 5000)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');
        $this->assertSame(2, Batch::count());
    }

    public function test_an_admin_set_ceiling_replaces_the_default(): void
    {
        \App\Models\Setting::set('batch_ceilings.cheese', 500);
        [$cheese, $wheel] = $this->cheese();

        $this->postBatch($cheese, [['variant_id' => $wheel->id, 'quantity_produced' => 300]], 3000)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->postBatch($cheese, [['variant_id' => $wheel->id, 'quantity_produced' => 501]], 5000)
            ->assertSessionHas('warning')
            ->assertSessionHasErrors(['batch_items.0.quantity_produced' => "501 × Whole Wheel — the usual ceiling is 500. Tick 'Yes, this batch really is that big' to save it."]);
        $this->assertSame(1, Batch::count());
    }

    public function test_milk_cannot_bottle_more_than_its_raw_milk(): void
    {
        [$milk, $oneLitre] = $this->makeMilkProductWithTwoVariants();

        $this->postBatch($milk, [['variant_id' => $oneLitre->id, 'quantity_produced' => 120]], 100)
            ->assertSessionHasErrors(['raw_milk_litres' => 'Bottled 120 L is more than the 100 L of raw milk entered.']);
        $this->assertSame(0, Batch::count());

        $this->postBatch($milk, [['variant_id' => $oneLitre->id, 'quantity_produced' => 100]], 100)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Batch::count());
    }
}
