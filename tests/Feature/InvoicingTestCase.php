<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixture for the invoicing suite: a shop, a milk and a cheese variant,
 * and helpers to put a delivered order in front of the billing code.
 */
abstract class InvoicingTestCase extends TestCase
{
    use RefreshDatabase;

    protected Customer $shop;

    protected ProductVariant $bottle;

    protected ProductVariant $wheel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Customer::create([
            'name' => 'Kerry Organic Store',
            'email' => 'k@example.ie',
            'address' => '1 Main St', 'city' => 'Tralee', 'postal_code' => 'V92 AB12',
            'country' => 'Ireland',
            'payment_terms' => 'net_30',
            'credit_limit' => 5000,
            'is_active' => true,
        ]);

        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'base_price' => 1.50, 'is_active' => true,
        ]);

        $cheese = Product::create(['name' => 'Farmhouse Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
        $this->wheel = ProductVariant::create([
            'product_id' => $cheese->id,
            'name' => 'Whole Wheel', 'size' => '4kg', 'unit' => 'wheel',
            'weight_kg' => 4.0, 'base_price' => 10.00,
            'is_variable_weight' => true, 'is_priced_by_weight' => true, 'is_active' => true,
        ]);
    }

    /** A delivered order for the shop with one fully-picked milk line. */
    protected function deliveredOrder(
        int $quantity = 10,
        string $status = 'delivered',
        ?Customer $customer = null,
        ?string $deliveryDate = null,
        float $deliveryCharge = 0,
    ): Order {
        $customer ??= $this->shop;

        $order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => $deliveryDate ?? today()->toDateString(),
            'delivery_date' => $deliveryDate ?? today()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'delivery_charge' => $deliveryCharge,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->bottle->id,
            'quantity_ordered' => $quantity,
            'quantity_fulfilled' => $quantity,
            'unit_price' => 1.50,
        ]);

        $order->calculateTotals();

        return $order->fresh('orderItems');
    }

    /** Stock to pick from, so allocation-dependent paths have something real. */
    protected function batchItemFor(ProductVariant $variant, int $quantity = 100): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $variant->product_id,
            'batch_code' => 'B'.now()->format('dmy').'-'.$variant->id,
            'production_date' => today()->subDays(2),
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => $quantity,
            'quantity_remaining' => $quantity,
        ]);
    }

    protected function issue(Order $order, $by = null): \App\Models\Invoice
    {
        return app(InvoiceService::class)->issueForOrder($order, $by);
    }
}
