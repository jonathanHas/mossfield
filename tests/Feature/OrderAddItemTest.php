<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Items can be added to an order after creation (including a fully-picked
 * "ready" order) via POST /orders/{order}/items. Adding a variant already on
 * the order merges into that line; adding to a ready order reopens picking
 * (ready → preparing). Closed orders (dispatched/delivered/cancelled) reject.
 */
class OrderAddItemTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Test Milk',
            'type' => 'milk',
            'is_active' => true,
        ]);

        $this->variantA = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.0,
            'base_price' => 2.00,
            'is_active' => true,
        ]);

        $this->variantB = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => '2L Bottle',
            'size' => '2L',
            'unit' => 'bottle',
            'weight_kg' => 2.0,
            'base_price' => 3.00,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market',
            'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar',
            'city' => 'Dublin',
            'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);
    }

    private function makeOrder(string $status, int $qtyA = 5): Order
    {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->variantA->id,
            'quantity_ordered' => $qtyA,
            'unit_price' => $this->variantA->base_price,
        ]);

        return $order->fresh(['orderItems']);
    }

    public function test_office_adds_a_new_item_to_a_ready_order_and_status_reverts_to_preparing(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready'); // 5 × €2 = €10

        $response = $this->actingAs($office)
            ->post(route('orders.items.store', $order), [
                'product_variant_id' => $this->variantB->id,
                'quantity' => 2, // 2 × €3 = €6
            ]);

        $order->refresh()->load('orderItems');
        // Lands on the new line (cycle 4).
        $response->assertRedirect(route('orders.show', $order).'#line-'.$order->orderItems->firstWhere('product_variant_id', $this->variantB->id)->id);
        $this->assertCount(2, $order->orderItems);
        $this->assertSame('preparing', $order->status);
        $this->assertSame('16.00', (string) $order->total_amount);
    }

    public function test_adding_an_existing_variant_merges_into_that_line(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed', qtyA: 5);

        $this->actingAs($office)
            ->post(route('orders.items.store', $order), [
                'product_variant_id' => $this->variantA->id,
                'quantity' => 3,
            ])
            ->assertRedirect(route('orders.show', $order).'#line-'.$order->orderItems->first()->id);

        $order->refresh()->load('orderItems');
        $this->assertCount(1, $order->orderItems, 'Merged into the existing line, no duplicate row.');
        $this->assertSame(8, (int) $order->orderItems->first()->quantity_ordered);
        $this->assertSame('16.00', (string) $order->total_amount); // 8 × €2
    }

    public function test_adding_to_a_confirmed_order_leaves_status_confirmed(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');

        $this->actingAs($office)
            ->post(route('orders.items.store', $order), [
                'product_variant_id' => $this->variantB->id,
                'quantity' => 1,
            ])->assertRedirectContains(route('orders.show', $order).'#line-');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_factory_cannot_add_items(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $order = $this->makeOrder('confirmed');

        $this->actingAs($factory)
            ->post(route('orders.items.store', $order), [
                'product_variant_id' => $this->variantB->id,
                'quantity' => 1,
            ])->assertForbidden();

        $this->assertCount(1, $order->fresh()->orderItems);
    }

    public function test_cannot_add_items_to_a_dispatched_order(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('dispatched');

        $this->actingAs($office)
            ->from(route('orders.show', $order))
            ->post(route('orders.items.store', $order), [
                'product_variant_id' => $this->variantB->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $this->assertCount(1, $order->fresh()->orderItems);
        $this->assertSame('dispatched', $order->fresh()->status);
    }

    public function test_add_item_form_visible_on_open_order_and_hidden_on_delivered(): void
    {
        $office = User::factory()->create();

        $open = $this->makeOrder('confirmed');
        $this->actingAs($office)
            ->get(route('orders.show', $open))
            ->assertOk()
            ->assertSee(route('orders.items.store', $open));

        $delivered = $this->makeOrder('delivered');
        $this->actingAs($office)
            ->get(route('orders.show', $delivered))
            ->assertOk()
            ->assertDontSee(route('orders.items.store', $delivered));
    }

    public function test_add_item_quantity_starts_blank_and_blank_means_one(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');

        $html = $this->actingAs($office)->get(route('orders.show', $order))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="add_quantity"[^>]*placeholder="1"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="add_quantity"[^>]*value="1"/', $html);

        $this->actingAs($office)
            ->post(route('orders.items.store', $order), [
                'product_variant_id' => $this->variantB->id,
                'quantity' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, (int) $order->orderItems()->where('product_variant_id', $this->variantB->id)->value('quantity_ordered'));
    }

    public function test_the_two_line_boxes_are_labelled_and_fulfil_asks_first(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');
        $batch = \App\Models\Batch::create([
            'product_id' => $this->product->id, 'production_date' => now()->subDay(),
            'expiry_date' => now()->addDays(5), 'raw_milk_litres' => 10, 'status' => 'active',
        ]);
        $batchItem = \App\Models\BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->variantA->id,
            'quantity_produced' => 10, 'quantity_remaining' => 10, 'unit_weight_kg' => 1.0,
        ]);
        $order->orderItems->first()->allocateFromBatchItem($batchItem, 5);

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('aria-label="Units to pick now"', false)
            ->assertSee('>Pick</label>', false)
            ->assertSee('Ordered:')
            ->assertSee('data-confirm="Record a pick of 5 × Test Milk · 1L Bottle from batch '.$batch->batch_code.'?"', false)
            ->assertSee('data-confirm-label="Record pick"', false);
    }
}
