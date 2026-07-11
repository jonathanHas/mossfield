<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSpecialPriceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Customer $other;

    private ProductVariant $bottle;

    private ProductVariant $wheel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market',
            'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar', 'city' => 'Dublin', 'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);

        $this->other = Customer::create([
            'name' => 'Cork Deli',
            'email' => 'buy@corkdeli.ie',
            'address' => 'Oliver Plunkett St', 'city' => 'Cork', 'postal_code' => 'T12 AB12',
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
            'weight_kg' => 4.0, 'base_price' => 12.00,
            'is_variable_weight' => true, 'is_priced_by_weight' => true, 'is_active' => true,
        ]);
    }

    public function test_order_store_uses_the_customer_special_price(): void
    {
        $office = User::factory()->create();
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->bottle->id,
            'price' => 1.20,
        ]);

        $this->actingAs($office)
            ->post(route('orders.store'), [
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'delivery_date' => null,
                'delivery_address' => null,
                'notes' => null,
                'items' => [['product_variant_id' => $this->bottle->id, 'quantity' => 3]],
            ])
            ->assertRedirect();

        $line = OrderItem::sole();
        $this->assertSame('1.20', (string) $line->unit_price);
        $this->assertSame('3.60', (string) $line->line_total); // 3 × 1.20
    }

    public function test_order_store_falls_back_to_base_price_for_customers_without_a_special(): void
    {
        $office = User::factory()->create();
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->bottle->id,
            'price' => 1.20,
        ]);

        // A different customer with no special price pays base_price.
        $this->actingAs($office)
            ->post(route('orders.store'), [
                'customer_id' => $this->other->id,
                'order_date' => now()->toDateString(),
                'delivery_date' => null,
                'delivery_address' => null,
                'notes' => null,
                'items' => [['product_variant_id' => $this->bottle->id, 'quantity' => 2]],
            ])
            ->assertRedirect();

        $this->assertSame('1.50', (string) OrderItem::sole()->unit_price);
    }

    public function test_weight_priced_special_flows_into_line_total(): void
    {
        $office = User::factory()->create();
        // €10/kg special instead of €12/kg base; line_total = qty × nominal weight × €/kg.
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->wheel->id,
            'price' => 10.00,
        ]);

        $this->actingAs($office)
            ->post(route('orders.store'), [
                'customer_id' => $this->customer->id,
                'order_date' => now()->toDateString(),
                'delivery_date' => null,
                'delivery_address' => null,
                'notes' => null,
                'items' => [['product_variant_id' => $this->wheel->id, 'quantity' => 2]],
            ])
            ->assertRedirect();

        $line = OrderItem::sole();
        $this->assertSame('10.00', (string) $line->unit_price);
        $this->assertSame('80.00', (string) $line->line_total); // 2 × 4kg × €10
    }

    public function test_store_update_and_destroy_manage_routes(): void
    {
        $office = User::factory()->create();

        // Store
        $this->actingAs($office)
            ->post(route('customers.special-prices.store', $this->customer), [
                'product_variant_id' => $this->bottle->id,
                'price' => 1.10,
            ])
            ->assertRedirect(route('customers.show', $this->customer));

        $sp = CustomerSpecialPrice::sole();
        $this->assertSame('1.10', (string) $sp->price);

        // Update
        $this->actingAs($office)
            ->put(route('customers.special-prices.update', [$this->customer, $sp]), [
                'product_variant_id' => $this->bottle->id,
                'price' => 1.05,
            ])
            ->assertRedirect(route('customers.show', $this->customer));
        $this->assertSame('1.05', (string) $sp->fresh()->price);

        // Destroy
        $this->actingAs($office)
            ->delete(route('customers.special-prices.destroy', [$this->customer, $sp]))
            ->assertRedirect(route('customers.show', $this->customer));
        $this->assertNull(CustomerSpecialPrice::find($sp->id));
    }

    public function test_duplicate_special_for_same_variant_is_rejected(): void
    {
        $office = User::factory()->create();
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->bottle->id,
            'price' => 1.20,
        ]);

        $this->actingAs($office)
            ->post(route('customers.special-prices.store', $this->customer), [
                'product_variant_id' => $this->bottle->id,
                'price' => 1.30,
            ])
            ->assertSessionHasErrors('product_variant_id');

        $this->assertSame(1, CustomerSpecialPrice::count());
    }

    public function test_customer_show_renders_the_special_prices_panel(): void
    {
        $office = User::factory()->create();
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->bottle->id,
            'price' => 1.20,
        ]);

        $this->actingAs($office)
            ->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertSee('Special prices')
            ->assertSee('1.20');
    }

    public function test_factory_user_cannot_manage_special_prices(): void
    {
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)
            ->post(route('customers.special-prices.store', $this->customer), [
                'product_variant_id' => $this->bottle->id,
                'price' => 1.10,
            ])
            ->assertForbidden();

        $this->assertSame(0, CustomerSpecialPrice::count());
    }
}
