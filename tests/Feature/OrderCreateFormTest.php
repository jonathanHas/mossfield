<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cycle 15 (UX review 2026-10-13, D22–D24): the order form names the run
 * that filled the delivery date, says what the delivery-address box is for,
 * and marks weight-priced line totals as estimates.
 */
class OrderCreateFormTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Customer $onRun;

    private Customer $offRun;

    private ProductVariant $wheel;

    private ProductVariant $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();

        $run = DeliveryRun::create(['name' => 'Tuesday run', 'day_of_week' => 2, 'is_active' => true]);
        $this->onRun = Customer::create([
            'name' => 'Barnhill Stores', 'email' => 'barnhill@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true,
            'delivery_run_id' => $run->id, 'run_position' => 1,
        ]);
        $this->offRun = Customer::create([
            'name' => 'Corner Shop', 'email' => 'corner@example.ie',
            'address' => 'High St', 'city' => 'Bray', 'postal_code' => 'A98', 'is_active' => true,
        ]);

        $cheese = Product::create(['name' => 'Mature Cheese', 'type' => 'cheese', 'maturation_days' => 150, 'is_active' => true]);
        $this->wheel = ProductVariant::create([
            'product_id' => $cheese->id, 'name' => 'Whole Wheel', 'size' => '5kg', 'unit' => 'wheel',
            'weight_kg' => 5.0, 'base_price' => 20.00,
            'is_variable_weight' => true, 'is_priced_by_weight' => true, 'is_active' => true,
        ]);

        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 1.50, 'is_active' => true,
        ]);
    }

    public function test_the_form_knows_which_run_fills_each_customers_delivery_date(): void
    {
        $this->actingAs($this->office)->get(route('orders.create'))
            ->assertOk()
            ->assertViewHas('customerRuns', fn ($runs) => $runs[$this->onRun->id] === 'Tuesday run · every Tuesday'
                && ! isset($runs[$this->offRun->id]))
            ->assertSee('id="deliveryDateHint"', false);
    }

    public function test_a_whole_week_run_reads_any_day_that_week(): void
    {
        $weekly = DeliveryRun::create(['name' => 'Midlands', 'day_of_week' => null, 'is_active' => true]);
        $this->offRun->update(['delivery_run_id' => $weekly->id, 'run_position' => 1]);

        $this->actingAs($this->office)->get(route('orders.create'))
            ->assertViewHas('customerRuns', fn ($runs) => $runs[$this->offRun->id] === 'Midlands · any day that week');
    }

    public function test_the_delivery_address_box_says_it_is_an_override(): void
    {
        $sentence = 'Leave blank to deliver to the customer\'s address.';

        $this->actingAs($this->office)->get(route('orders.create'))
            ->assertOk()
            ->assertSee('Delivery address (if different)')
            ->assertSee($sentence, false);

        $order = Order::create([
            'customer_id' => $this->offRun->id, 'order_date' => now()->toDateString(),
            'status' => 'pending', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);

        $this->actingAs($this->office)->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('Delivery address (if different)')
            ->assertSee($sentence, false);
    }

    public function test_weight_priced_options_carry_their_nominal_weight_for_the_estimate(): void
    {
        $this->actingAs($this->office)->get(route('orders.create'))
            ->assertOk()
            ->assertSee('value="'.$this->wheel->id.'" data-price="100" data-picker="Mature Cheese · Whole Wheel" data-by-weight="1" data-weight="5"', false)
            ->assertSee('id="orderEstimateNote"', false)
            ->assertSee('Estimated from nominal weights — the invoice uses the picked weight.');
    }

    public function test_a_fixed_price_option_is_not_marked_by_weight(): void
    {
        $this->actingAs($this->office)->get(route('orders.create'))
            ->assertOk()
            ->assertSee('value="'.$this->bottle->id.'" data-price="1.5" data-picker="Organic Milk · 1L Bottle" data-by-weight="0" data-weight=""', false);
    }
}
