<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCustomerReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market',
            'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar',
            'city' => 'Dublin',
            'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);

        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->variant = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'base_price' => 1.50,
            'is_active' => true,
        ]);
    }

    public function test_store_persists_an_optional_customer_reference(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('orders.store'), [
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'delivery_date' => null,
                'delivery_address' => null,
                'notes' => null,
                'customer_reference' => 'PO-9001',
                'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]],
            ])
            ->assertRedirect();

        $this->assertSame('PO-9001', Order::sole()->customer_reference);
    }

    public function test_store_without_a_reference_leaves_it_null(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('orders.store'), [
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'delivery_date' => null,
                'delivery_address' => null,
                'notes' => null,
                'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]],
            ])
            ->assertRedirect();

        $this->assertNull(Order::sole()->customer_reference);
    }

    public function test_update_persists_the_reference(): void
    {
        $office = User::factory()->create();
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'pending',
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $this->actingAs($office)
            ->patch(route('orders.update', $order), [
                'status' => 'confirmed',
                'payment_status' => 'pending',
                'customer_reference' => 'PO-7777',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('PO-7777', $order->fresh()->customer_reference);
    }

    public function test_reference_survives_a_status_only_patch_via_status_fields(): void
    {
        // The show-page inline Confirm/Cancel forms replay $statusFields as hidden
        // inputs — customer_reference must be among them or it would be blanked.
        $office = User::factory()->create();
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'customer_reference' => 'PO-KEEP',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        // Render the show page and confirm the hidden field carries the value.
        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('PO-KEEP');

        // A status-advancing PATCH that replays the field keeps it.
        $this->actingAs($office)
            ->patch(route('orders.update', $order), [
                'status' => 'preparing',
                'payment_status' => 'pending',
                'delivery_address' => null,
                'notes' => null,
                'customer_reference' => 'PO-KEEP',
            ])
            ->assertRedirect();

        $this->assertSame('PO-KEEP', $order->fresh()->customer_reference);
    }

    public function test_requires_reference_flag_auto_expands_the_field_on_create(): void
    {
        $office = User::factory()->create();
        $this->customer->update(['requires_reference' => true]);

        // The create form seeds the Alpine map so the reveal opens for this customer.
        $this->actingAs($office)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertSee('name="customer_reference"', false)
            ->assertSee('requiresRef', false);
    }
}
