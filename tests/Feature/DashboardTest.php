<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard batch figures: "ready now" counts only sellable batches, and
 * expired stock (still active, past expiry, units left) gets its own tile
 * (E2E findings F-01, F-02). The order queue says what to do next — confirm
 * a pending order, allocate a confirmed one — and flags a passed delivery
 * date (UX review A2, A3, B9, C11, C16).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private int $day = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $milk = Product::create(['name' => 'Test Milk', 'type' => 'milk', 'is_active' => true]);
        $this->variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'is_active' => true,
        ]);
    }

    private function batch($expiry, int $remaining): Batch
    {
        $batch = Batch::create([
            'product_id' => $this->variant->product_id,
            'production_date' => now()->subDays($this->day--),
            'expiry_date' => $expiry,
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->variant->id,
            'quantity_produced' => 50, 'quantity_remaining' => $remaining, 'unit_weight_kg' => 1.0,
        ]);

        return $batch;
    }

    public function test_ready_now_counts_only_sellable_batches_and_expired_stock_has_a_tile(): void
    {
        $this->batch(now()->addDays(5), 50);   // sellable
        $this->batch(now()->subDay(), 12);     // expired, with stock
        $this->batch(now()->subDays(2), 0);    // expired, empty

        $office = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $office->assertViewHas('readyNowCount', 1);
        $office->assertViewHas('expiredCount', 1);
        $office->assertViewHas('expiredUnits', 12);
        $office->assertSee('1 ready now');
        $office->assertSee('Expired stock');
        $office->assertSee('12 units · write off'); // cycle 7: write-offs exist now
        $office->assertSee(route('batches.index', ['status' => 'expired']), false);

        $this->actingAs(User::factory()->factoryWorker()->create())->get('/dashboard')
            ->assertOk()
            ->assertSee('Expired stock');
    }

    public function test_the_tile_is_absent_when_nothing_is_expired(): void
    {
        $this->batch(now()->addDays(5), 50);
        $this->batch(now()->subDays(2), 0);

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertViewHas('expiredCount', 0)
            ->assertDontSee('Expired stock');
    }

    public function test_the_expired_filter_on_batches_includes_active_batches_past_expiry(): void
    {
        $fresh = $this->batch(now()->addDays(5), 50);
        $stale = $this->batch(now()->subDay(), 12);

        $this->actingAs(User::factory()->create())->get(route('batches.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee($stale->batch_code)
            ->assertDontSee($fresh->batch_code);
    }

    private function order(string $status, $deliveryDate = null): Order
    {
        $customer = Customer::firstOrCreate(['email' => 'shop@example.ie'], [
            'name' => 'Corner Shop', 'address' => 'Main St', 'city' => 'Wicklow',
            'postal_code' => 'A67', 'is_active' => true,
        ]);
        $order = Order::create([
            'customer_id' => $customer->id, 'order_date' => now()->toDateString(),
            'delivery_date' => $deliveryDate, 'status' => $status, 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $this->variant->id,
            'quantity_ordered' => 5, 'unit_price' => 2.50,
        ]);

        return $order;
    }

    public function test_the_queue_offers_confirm_for_pending_and_allocate_for_confirmed(): void
    {
        $this->order('pending');
        $this->order('confirmed');

        $page = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'Confirm →'));
        $this->assertSame(1, substr_count($html, 'Allocate →'));
        $page->assertSee('To do: 1 order to confirm · 1 order to allocate.');
        $page->assertSee('Orders to action');
        $page->assertSee('1 to confirm · 1 to allocate');
        $page->assertSee('To confirm or allocate · oldest first');
        $page->assertDontSee('>Items</th>', false); // cycle 13 rev 2: column dropped so the action fits
        $page->assertSee('Showing 2 of 2 to action');
    }

    public function test_one_order_to_allocate_is_singular(): void
    {
        $this->order('confirmed');

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertSee('1 order to allocate')
            ->assertDontSee('need allocation')
            ->assertDontSee('Confirm →');
    }

    public function test_a_passed_delivery_date_is_flagged_only_while_the_order_is_open(): void
    {
        $this->order('confirmed', now()->subDay()->toDateString());

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertSee('<span class="mf-tag mf-tag-danger">overdue</span>', false)
            ->assertSee('1 past its delivery date');

        Order::query()->update(['status' => 'dispatched']);

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertDontSee('<span class="mf-tag mf-tag-danger">overdue</span>', false)
            ->assertDontSee('past its delivery date')
            ->assertSee('Everything is on track.');
    }

    public function test_the_placeholder_panels_are_gone(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertDontSee('Low-stock tracking')
            ->assertDontSee('Activity log')
            ->assertDontSee('not configured');
    }
}
