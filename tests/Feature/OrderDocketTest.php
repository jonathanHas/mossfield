<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dispatch docket (packing slip) PDF for orders — GET /orders/{order}/docket.
 * Carries no prices, so factory may download it (view ability); driver denied.
 */
class OrderDocketTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::create(['name' => 'Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Whole Wheel', 'size' => 'x', 'unit' => 'wheel',
            'weight_kg' => 4.0, 'base_price' => 12.00,
            'is_variable_weight' => true, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'name' => 'Kerry Organic', 'email' => 'k@example.ie',
            'address' => 'Main St', 'city' => 'Tralee', 'postal_code' => 'V92', 'is_active' => true,
        ]);

        $this->order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'status' => 'ready',
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $this->order->orderItems()->create([
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 2,
            'unit_price' => $variant->base_price,
        ]);
    }

    private function attachFulfilledBatch(string $code): void
    {
        $item = $this->order->orderItems()->first();
        $variant = $item->productVariant;

        $batch = Batch::create([
            'product_id' => $variant->product_id,
            'production_date' => now()->subDay()->toDateString(),
            'ready_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'batch_code' => $code,
            'status' => 'active',
        ]);
        $batchItem = BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => 10,
            'quantity_remaining' => 8,
            'unit_weight_kg' => $variant->weight_kg,
        ]);
        $item->orderAllocations()->create([
            'batch_item_id' => $batchItem->id,
            'quantity_allocated' => 2,
            'quantity_fulfilled' => 2,
            'allocated_at' => now(),
            'fulfilled_at' => now(),
        ]);
    }

    public function test_docket_shows_batch_codes_for_traceability(): void
    {
        $office = User::factory()->create();
        $this->attachFulfilledBatch('G010726-TEST');

        $this->actingAs($office)->get(route('orders.docket', $this->order))
            ->assertOk()
            ->assertSee('G010726-TEST');
    }

    public function test_office_views_the_docket_as_an_html_page(): void
    {
        $office = User::factory()->create(); // default role is office

        $response = $this->actingAs($office)->get(route('orders.docket', $this->order));

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('content-type'));
        // The HTML view carries the docket content plus Print / Download controls.
        $response->assertSee('Dispatch Docket');
        $response->assertSee('Kerry Organic');
        $response->assertSee('Print');
        $response->assertSee(route('orders.docket', ['order' => $this->order, 'download' => 1]));
    }

    public function test_download_flag_returns_a_pdf_attachment(): void
    {
        $office = User::factory()->create();

        $response = $this->actingAs($office)->get(route('orders.docket', ['order' => $this->order, 'download' => 1]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString(
            'docket-'.$this->order->order_number.'.pdf',
            $response->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_factory_can_view_the_docket(): void
    {
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)->get(route('orders.docket', $this->order))->assertOk();
    }

    public function test_driver_is_denied_the_docket(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get(route('orders.docket', $this->order))->assertForbidden();
    }
}
