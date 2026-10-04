<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 5 (docs/planImp/TESTING_AND_FIXES.md, 2026-09-12 browser pass): the per-wheel
 * weights[] on the fulfilment forms were summed in JavaScript into a hidden
 * actual_weight_kg and then discarded — the server never saw them and stored
 * whatever total the browser sent. Now the individual weights are what is
 * validated and persisted, and the total is recomputed from them.
 */
class FulfilmentUnitWeightsTest extends TestCase
{
    use RefreshDatabase;

    private Product $cheese;

    private Customer $shop;

    private ProductVariant $wheel;

    private ProductVariant $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cheese = Product::create(['name' => 'Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
        $this->shop = Customer::create([
            'name' => 'Kerry Organic', 'email' => 'k@example.ie',
            'address' => 'Main St', 'city' => 'Tralee', 'postal_code' => 'V92', 'is_active' => true,
        ]);
        $this->wheel = $this->variant([
            'name' => 'Whole Wheel', 'unit' => 'wheel', 'weight_kg' => 5.000, 'base_price' => 16.00,
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
        ]);
        $this->pack = $this->variant([
            'name' => 'Vacuum Pack', 'unit' => 'pack', 'weight_kg' => 0.250, 'base_price' => 18.00,
            'is_variable_weight' => true, 'is_bulk_weighed' => true, 'is_priced_by_weight' => true,
        ]);
    }

    private function variant(array $attrs): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $this->cheese->id, 'size' => 'x', 'unit' => 'x', 'is_active' => true,
        ], $attrs));
    }

    private function batchItem(ProductVariant $variant, int $remaining = 10): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->cheese->id,
            'production_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        // Batch::boot() sets a cheese ready_date from maturation_days; backdate
        // it so the pick screen actually offers this batch.
        $batch->forceFill(['ready_date' => now()->subDay()->toDateString()])->save();

        return BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => $remaining, 'quantity_remaining' => $remaining, 'unit_weight_kg' => $variant->weight_kg,
        ]);
    }

    /** @return array{Order, OrderItem, OrderAllocation} */
    private function allocated(ProductVariant $variant, int $qty): array
    {
        $order = Order::create([
            'customer_id' => $this->shop->id, 'order_date' => now()->toDateString(),
            'status' => 'preparing', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $item = $order->orderItems()->create([
            'product_variant_id' => $variant->id, 'quantity_ordered' => $qty, 'unit_price' => $variant->base_price,
        ]);
        $alloc = $item->allocateFromBatchItem($this->batchItem($variant), $qty);

        return [$order, $item, $alloc];
    }

    /** @return array{Order, OrderItem, BatchItem} */
    private function unallocated(ProductVariant $variant, int $qty): array
    {
        $order = Order::create([
            'customer_id' => $this->shop->id, 'order_date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $item = $order->orderItems()->create([
            'product_variant_id' => $variant->id, 'quantity_ordered' => $qty, 'unit_price' => $variant->base_price,
        ]);

        return [$order, $item, $this->batchItem($variant)];
    }

    // ---------------------------------------------- the finding, both forms

    public function test_office_fulfil_stores_the_unit_weights_and_recomputes_the_total(): void
    {
        [$order, $item, $alloc] = $this->allocated($this->wheel, 2);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.fulfill', $alloc), [
                'quantity' => 2,
                'weights' => [4.8, 5.2],
                'actual_weight_kg' => 100, // the tampered / mis-summed client total
            ])->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();

        $alloc->refresh();
        $this->assertSame('10.000', (string) $alloc->actual_weight_kg, 'the total is the sum of the weights, not what the browser said');
        $this->assertSame([4.8, 5.2], $alloc->unit_weights_kg);
        $this->assertSame('10.000', (string) $item->fresh()->weight_fulfilled_kg);
        $this->assertSame('160.00', (string) $item->fresh()->fulfilled_total, '10 kg × €16');
    }

    public function test_picking_pick_stores_the_unit_weights_and_recomputes_the_total(): void
    {
        [$order, $item, $batchItem] = $this->unallocated($this->wheel, 2);

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('picking.pick', [$order, $item]), [
                'batch_item_id' => $batchItem->id,
                'quantity' => 2,
                'weights' => ['4.800', '5.200'],
                'actual_weight_kg' => 100,
            ])->assertRedirect(route('picking.show', $order))->assertSessionHasNoErrors();

        $alloc = $item->orderAllocations()->sole();
        $this->assertSame('10.000', (string) $alloc->actual_weight_kg);
        $this->assertSame([4.8, 5.2], $alloc->unit_weights_kg);
    }

    // ------------------------------------------------------- validation

    public function test_a_per_unit_line_requires_one_weight_per_unit_picked(): void
    {
        [$order, $item, $alloc] = $this->allocated($this->wheel, 3);
        $office = User::factory()->create();

        // No weights at all — the old actual_weight_kg-only shape is no longer enough.
        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 3, 'actual_weight_kg' => 15.0,
        ])->assertSessionHasErrors('weights');

        // Too few, too many, a non-weight, a zero.
        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 3, 'weights' => [5.0, 5.0],
        ])->assertSessionHasErrors('weights');
        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 2, 'weights' => [5.0, 5.0, 5.0],
        ])->assertSessionHasErrors('weights');
        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 3, 'weights' => [5.0, 'heavy', 5.0],
        ])->assertSessionHasErrors('weights.1');
        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 3, 'weights' => [5.0, 0, 5.0],
        ])->assertSessionHasErrors('weights.1');

        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
    }

    public function test_a_bulk_weighed_line_still_takes_one_total(): void
    {
        [$order, $item, $alloc] = $this->allocated($this->pack, 4);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.fulfill', $alloc), [
                'quantity' => 4, 'actual_weight_kg' => 1.050,
            ])->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();

        $alloc->refresh();
        $this->assertSame('1.050', (string) $alloc->actual_weight_kg);
        $this->assertNull($alloc->unit_weights_kg, 'bulk lines have no per-unit figures');
    }

    // ------------------------------------------------- partial pick + undo

    public function test_a_second_partial_pick_appends_its_weights(): void
    {
        [$order, $item, $alloc] = $this->allocated($this->wheel, 3);
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 2, 'weights' => [4.8, 5.2],
        ])->assertSessionHasNoErrors();
        $this->actingAs($office)->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 1, 'weights' => [5.5],
        ])->assertSessionHasNoErrors();

        $alloc->refresh();
        $this->assertSame([4.8, 5.2, 5.5], $alloc->unit_weights_kg);
        $this->assertSame('15.500', (string) $alloc->actual_weight_kg);
    }

    public function test_undoing_units_removes_their_own_weights_not_an_average(): void
    {
        [$order, $item, $alloc] = $this->allocated($this->wheel, 3);

        $this->actingAs(User::factory()->create())->post(route('order-allocations.fulfill', $alloc), [
            'quantity' => 3, 'weights' => [4.0, 5.0, 6.0],
        ])->assertSessionHasNoErrors();

        // Undo the last one: the 6.0 kg wheel goes back, not a 5.0 kg average.
        $this->assertTrue($item->fresh()->unfulfillAllocation($alloc->fresh(), 1));

        $alloc->refresh();
        $this->assertSame([4.0, 5.0], $alloc->unit_weights_kg);
        $this->assertSame('9.000', (string) $alloc->actual_weight_kg);
        $this->assertSame('9.000', (string) $item->fresh()->weight_fulfilled_kg);

        $this->assertTrue($item->fresh()->unfulfillAllocation($alloc->fresh(), 2));
        $alloc->refresh();
        $this->assertSame([], $alloc->unit_weights_kg);
        $this->assertSame('0.000', (string) $alloc->actual_weight_kg);
    }

    // ---------------------------------------------------------- the forms

    public function test_both_forms_post_the_weights_by_name(): void
    {
        [$order, $item, $alloc] = $this->allocated($this->wheel, 2);

        $this->actingAs(User::factory()->create())->get(route('orders.show', $order))
            ->assertOk()->assertSee('name="weights[]"', false);

        [$order2, $item2] = $this->unallocated($this->wheel, 2);
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('picking.item', [$order2, $item2]))
            ->assertOk()->assertSee('name="weights[]"', false);
    }
}
