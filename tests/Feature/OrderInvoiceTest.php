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
 * Order invoice — GET /orders/{order}/invoice. Unlike the dispatch docket it carries
 * prices, so it is office/admin only (route group role:admin,office + see-financials);
 * factory and driver are denied. HTML view by default, PDF via ?download=1.
 */
class OrderInvoiceTest extends TestCase
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
            'weight_kg' => 4.0, 'base_price' => 12.50, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'name' => 'Kerry Organic', 'email' => 'k@example.ie',
            'address' => 'Main St', 'city' => 'Tralee', 'postal_code' => 'V92',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);

        $this->order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'status' => 'ready',
            'payment_status' => 'pending',
            'subtotal' => 25.00, 'tax_amount' => 0, 'total_amount' => 25.00,
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

    public function test_invoice_shows_batch_codes_for_traceability(): void
    {
        $office = User::factory()->create();
        $this->attachFulfilledBatch('G010726-INV');

        $this->actingAs($office)->get(route('orders.invoice', $this->order))
            ->assertOk()
            ->assertSee('G010726-INV');
    }

    public function test_office_views_the_invoice_as_html_with_prices(): void
    {
        $office = User::factory()->create(); // default role is office

        $response = $this->actingAs($office)->get(route('orders.invoice', $this->order));

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('content-type'));
        $response->assertSee('Invoice');
        $response->assertSee('Kerry Organic');
        $response->assertSee('Net 30 days');
        $response->assertSee('€25.00'); // total is shown — this is the priced document
    }

    public function test_admin_can_download_the_invoice_pdf(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('orders.invoice', ['order' => $this->order, 'download' => 1]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString(
            'invoice-'.$this->order->order_number.'.pdf',
            $response->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_invoice_is_unavailable_before_the_order_reaches_ready(): void
    {
        $office = User::factory()->create();
        $this->order->update(['status' => 'confirmed']);

        $this->actingAs($office)->get(route('orders.invoice', $this->order))
            ->assertRedirect(route('orders.show', $this->order))
            ->assertSessionHas('error');
    }

    public function test_factory_is_denied_the_invoice(): void
    {
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)->get(route('orders.invoice', $this->order))->assertForbidden();
    }

    public function test_driver_is_denied_the_invoice(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get(route('orders.invoice', $this->order))->assertForbidden();
    }
}
