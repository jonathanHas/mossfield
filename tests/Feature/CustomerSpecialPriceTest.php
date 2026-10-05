<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\DeliveryRun;
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

    public function test_dispatch_confirmation_unit_price_column_shows_the_special_price_not_the_base_price(): void
    {
        $office = User::factory()->create();

        // €1.20 special on the bottle (base is €1.50).
        $order = \App\Models\Order::create([
            'order_number' => 'ORD-20260724-001',
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'ready',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->bottle->id,
            'quantity_ordered' => 3,
            'unit_price' => 1.20,
        ]);

        $this->actingAs($office)
            ->get(route('orders.dispatch-confirmation', $order))
            ->assertOk()
            ->assertSee('€1.20')       // locked special price in the Unit price column
            ->assertDontSee('€1.50');  // never the variant base_price
    }

    public function test_dispatch_confirmation_weight_priced_unit_price_shows_per_kg_special(): void
    {
        $office = User::factory()->create();

        // €10/kg special on the wheel (base is €12/kg).
        $order = \App\Models\Order::create([
            'order_number' => 'ORD-20260724-002',
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'ready',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->wheel->id,
            'quantity_ordered' => 2,
            'unit_price' => 10.00,
        ]);

        $this->actingAs($office)
            ->get(route('orders.dispatch-confirmation', $order))
            ->assertOk()
            ->assertSee('€10.00/kg')       // locked special €/kg
            ->assertDontSee('€12.00/kg');  // never the variant base €/kg
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
            ->assertRedirect(route('customers.show', $this->customer).'#special-prices');

        $sp = CustomerSpecialPrice::sole();
        $this->assertSame('1.10', (string) $sp->price);

        // Update
        $this->actingAs($office)
            ->put(route('customers.special-prices.update', [$this->customer, $sp]), [
                'product_variant_id' => $this->bottle->id,
                'price' => 1.05,
            ])
            ->assertRedirect(route('customers.show', $this->customer).'#special-prices');
        $this->assertSame('1.05', (string) $sp->fresh()->price);

        // Destroy
        $this->actingAs($office)
            ->delete(route('customers.special-prices.destroy', [$this->customer, $sp]))
            ->assertRedirect(route('customers.show', $this->customer).'#special-prices');
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

    public function test_the_order_form_previews_the_customers_price_and_delivery(): void
    {
        // E2E F-20: the create form previewed standard prices and no delivery
        // charge, so its total wasn't what store() then saved.
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->bottle->id,
            'price' => 1.25,
        ]);
        CustomerSpecialPrice::create([
            'customer_id' => $this->customer->id,
            'product_variant_id' => $this->wheel->id,
            'price' => 10.00, // €/kg → 4 kg nominal ⇒ €40.00 a wheel
        ]);
        $run = DeliveryRun::create(['name' => 'Dublin', 'day_of_week' => 2, 'is_active' => true, 'delivery_charge' => 6.15]);
        $this->customer->update(['delivery_run_id' => $run->id, 'run_position' => 1, 'apply_delivery_charge' => true]);
        $this->other->update(['delivery_charge_percent' => 5]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('orders.create'))
            ->assertOk();

        $page = $response->getContent();
        $prices = json_decode($this->embedded($page, 'customerPrices'), true);
        $charges = json_decode($this->embedded($page, 'customerCharges'), true);

        $this->assertEquals(1.25, $prices[$this->customer->id][$this->bottle->id]);
        $this->assertEquals(40.00, $prices[$this->customer->id][$this->wheel->id], 'weight-priced specials are a per-unit estimate, like data-price');
        $this->assertArrayNotHasKey($this->other->id, $prices, 'no overrides → standard data-price');
        $this->assertEquals(['charge' => 6.15, 'percent' => null], $charges[$this->customer->id]);
        $this->assertEquals(['charge' => 0, 'percent' => 5], $charges[$this->other->id]);
        $response->assertSee('Delivery (incl. VAT)');
    }

    /** The JSON assigned to `const $name = …;` in the page's script. */
    private function embedded(string $page, string $name): string
    {
        $this->assertMatchesRegularExpression('/const '.$name.' = (.+?);\n/', $page);
        preg_match('/const '.$name.' = (.+?);\n/', $page, $m);

        return $m[1];
    }

    public function test_the_add_select_omits_variants_that_already_have_a_special(): void
    {
        $office = User::factory()->create();
        $this->actingAs($office)->post(route('customers.special-prices.store', $this->customer), [
            'product_variant_id' => $this->bottle->id, 'price' => 1.10,
        ]);

        $html = $this->actingAs($office)->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertSee('id="special-prices"', false)
            ->getContent();

        $select = substr($html, strpos($html, 'id="sp-variant"'));
        $select = substr($select, 0, strpos($select, '</select>'));
        $this->assertStringNotContainsString('value="'.$this->bottle->id.'"', $select);
    }

    public function test_a_new_customer_lands_on_its_own_page_and_delete_keeps_the_filter(): void
    {
        $office = User::factory()->create();

        $response = $this->actingAs($office)->post(route('customers.store'), [
            'name' => 'Brand New Shop', 'email' => 'new@example.ie', 'address' => 'Main St', 'city' => 'Bray',
            'postal_code' => 'A98', 'country' => 'Ireland', 'credit_limit' => 0, 'payment_terms' => 'net_30',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $new = Customer::where('name', 'Brand New Shop')->sole();
        $response->assertRedirect(route('customers.show', $new));

        $this->actingAs($office)->delete(route('customers.destroy', ['customer' => $new, 'search' => 'Brand', 'is_active' => '1']))
            ->assertRedirect(route('customers.index', ['search' => 'Brand', 'is_active' => '1']));
    }

    public function test_choosing_consolidated_billing_defaults_immediate_terms_to_net_14(): void
    {
        // OPEN_QUESTIONS #5, F-38b: client-side only; the flip itself is a browser check.
        $handler = "if (mode !== 'per_order' && terms === 'immediate') { terms = 'net_14'; \$refs.terms.value = 'net_14'; }";
        $office = User::factory()->create();

        $this->actingAs($office)->get(route('customers.create'))->assertOk()->assertSee($handler, false);
        $this->actingAs($office)->get(route('customers.edit', $this->customer))->assertOk()->assertSee($handler, false);
    }
}
