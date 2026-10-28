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
 * UX review 2026-10-13, part 1: screens that say what is true — Recent
 * batches in units, one word per status, "No limit" for a zero credit
 * limit, batch cards that don't contradict themselves, no dead controls.
 */
class UxCopyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create(['name' => 'Test Milk', 'type' => 'milk', 'is_active' => true]);
        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'is_active' => true,
        ]);
    }

    private function batch($expiry, int $produced = 40, int $daysAgo = 10): Batch
    {
        $batch = Batch::create([
            'product_id' => $this->product->id,
            'production_date' => now()->subDays($daysAgo),
            'expiry_date' => $expiry,
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->variant->id,
            'quantity_produced' => $produced, 'quantity_remaining' => $produced, 'unit_weight_kg' => 1.0,
        ]);

        return $batch;
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Corner Shop', 'email' => uniqid().'@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true,
        ], $attrs));
    }

    // ── Step 5: Recent batches on the product page ───────────────────────

    public function test_product_page_recent_batches_show_expired_and_produced_units(): void
    {
        $this->batch(now()->subDay(), 37, 12);

        $this->actingAs(User::factory()->create())->get(route('products.show', $this->product))
            ->assertOk()
            ->assertSee('Produced')
            ->assertSee('Expired')
            ->assertSee('<span class="mf-tag mf-tag-danger">Expired</span>', false)
            ->assertDontSee(' kg</td>', false);
    }

    public function test_product_page_fresh_batch_shows_its_produced_units(): void
    {
        $this->batch(now()->addDays(7), 48)->batchItems()->update(['quantity_remaining' => 30]);

        $html = $this->actingAs(User::factory()->create())->get(route('products.show', $this->product))
            ->assertOk()
            ->assertDontSee('Expired')
            ->getContent();

        $this->assertMatchesRegularExpression('#<td class="mf-td font-mono">48</td>#', $html);
    }

    // ── Step 9: one word per status ──────────────────────────────────

    public function test_a_preparing_order_reads_picking(): void
    {
        $order = Order::create([
            'customer_id' => $this->customer()->id, 'order_date' => now()->toDateString(),
            'status' => 'preparing', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $this->assertSame('Picking', $order->statusLabel());
        $this->assertSame('Confirmed', $order->replicate()->fill(['status' => 'confirmed'])->statusLabel());

        $this->actingAs(User::factory()->create())->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Picking')
            ->assertDontSee('Preparing');
    }

    // ── Step 10: credit limit and the duplicate figure ───────────────

    public function test_a_zero_credit_limit_reads_no_limit(): void
    {
        $office = User::factory()->create();
        $open = $this->customer(['name' => 'Open Account', 'credit_limit' => 0]);
        $capped = $this->customer(['name' => 'Capped Account', 'credit_limit' => 500]);

        $this->actingAs($office)->get(route('customers.show', $open))
            ->assertOk()
            ->assertSee('No limit')
            ->assertDontSee('€0.00</dd>', false)
            ->assertDontSee('>Outstanding</dt>', false)
            ->assertSee('Net owed');

        $this->actingAs($office)->get(route('customers.show', $capped))
            ->assertOk()
            ->assertSee('€500.00')
            ->assertDontSee('No limit');

        $this->actingAs($office)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('No limit')
            ->assertSee('€500.00');

        $hint = '0 = no limit — the app warns, never blocks, when an order would exceed it.';
        $this->actingAs($office)->get(route('customers.create'))->assertOk()->assertSee($hint);
        $this->actingAs($office)->get(route('customers.edit', $capped))->assertOk()->assertSee($hint);
    }

    // ── Step 11: batch cards stop contradicting themselves ───────────

    public function test_an_expired_active_batch_card_says_expired_once_and_not_active(): void
    {
        $batch = $this->batch(now()->subDay(), 20, 12);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('batches.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee($batch->batch_code)
            ->getContent();

        $this->assertSame(1, substr_count($html, '<span class="mf-tag mf-tag-danger">Expired</span>'));
        $this->assertStringNotContainsString('<span class="mf-tag mf-tag-accent">Active</span>', $html);
        $this->assertStringContainsString('<span style="color: var(--danger);">Expired</span>', $html);
    }

    public function test_the_batch_page_does_not_promise_more_actions(): void
    {
        $batch = $this->batch(now()->addDays(7));

        $this->actingAs(User::factory()->create())->get(route('batches.show', $batch))
            ->assertOk()
            ->assertDontSee('coming soon');
    }

    // ── Step 12: dead controls ───────────────────────────────────────

    public function test_profile_has_no_delete_account_panel_and_topbar_has_no_bell(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Delete account')
            ->assertDontSee('Delete Account')
            ->assertSee('Your account details and password.');

        $this->actingAs($office)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('aria-label="Notifications"', false);
    }
}
