<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The swallowed-feedback pattern behind findings 4, 6 and 9 of the 2026-09-12
 * browser pass: controllers were emitting the right flash / error bag, but the
 * app layout rendered neither, so every page had to opt in — and the pages a
 * refusal actually landed on (/stock, /orders/create for nested items.* keys)
 * hadn't. The layout now renders session flashes and the default error bag
 * once, for every page, so a refusal is visible wherever it redirects.
 */
class LayoutFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create(); // default role = office
    }

    public function test_flashes_are_rendered_on_a_page_that_never_opted_in(): void
    {
        $this->actingAs($this->office)
            ->withSession(['error' => 'That cut was refused: wheels are held.'])
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('That cut was refused: wheels are held.');

        $this->actingAs($this->office)
            ->withSession(['success' => 'Saved the thing.'])
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Saved the thing.');
    }

    public function test_a_flash_is_rendered_exactly_once_on_a_page_that_used_to_render_its_own(): void
    {
        $html = $this->actingAs($this->office)
            ->withSession(['success' => 'Order updated successfully.'])
            ->get(route('orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Order updated successfully.'),
            'the layout renders the flash; pages must not render it again');
    }

    /** @return array{Customer, ProductVariant} */
    private function orderFixtures(): array
    {
        $customer = Customer::create([
            'name' => 'Feedback Shop',
            'email' => 'feedback@example.ie',
            'address' => '1 Main St', 'city' => 'Birr', 'postal_code' => 'R42', 'country' => 'Ireland',
            'payment_terms' => 'net_30',
            'is_active' => true,
        ]);
        $milk = Product::create(['name' => 'Feedback Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'base_price' => 2.00, 'is_active' => true,
        ]);

        return [$customer, $bottle];
    }

    public function test_order_line_validation_errors_are_visible_with_a_human_label(): void
    {
        [$customer, $bottle] = $this->orderFixtures();

        $response = $this->actingAs($this->office)
            ->from(route('orders.create'))
            ->post(route('orders.store'), [
                'customer_id' => $customer->id,
                'order_date' => today()->toDateString(),
                'items' => [['product_variant_id' => $bottle->id, 'quantity' => -5]],
            ]);

        $response->assertRedirect(route('orders.create'))
            ->assertSessionHasErrors('items.0.quantity');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('must be at least 1')
            ->assertDontSee('items.0.quantity');
    }

    /**
     * Finding 6, remaining half: with several lines, the global bag says "The
     * quantity field must be at least 1." but not WHICH line. The message must
     * also render inline, inside the offending row, beside its input.
     */
    public function test_order_line_errors_render_inline_in_the_offending_row(): void
    {
        [$customer, $bottle] = $this->orderFixtures();

        $response = $this->actingAs($this->office)
            ->from(route('orders.create'))
            ->post(route('orders.store'), [
                'customer_id' => $customer->id,
                'order_date' => today()->toDateString(),
                'items' => [
                    ['product_variant_id' => $bottle->id, 'quantity' => 3],
                    ['product_variant_id' => $bottle->id, 'quantity' => -5],
                    // (A blank product is a discarded template row; an unknown id is the real failure.)
                    ['product_variant_id' => 999999, 'quantity' => 2],
                ],
            ]);

        $response->assertSessionHasErrors(['items.1.quantity', 'items.2.product_variant_id']);

        $html = $this->followRedirects($response)->assertOk()->getContent();

        // Split the items area into rows and look at each one on its own.
        preg_match_all('/<div class="order-item.*?(?=<div class="order-item|<x-input-error|id="orderTotal")/s', $html, $m);
        $rows = $m[0];
        $this->assertCount(3, $rows, 'all three posted lines are repopulated');

        $this->assertStringNotContainsString('must be at least 1', $rows[0], 'the valid first line carries no error');
        $this->assertStringContainsString('must be at least 1', $rows[1], 'the bad quantity is flagged in its own row');
        $this->assertStringNotContainsString('must be at least 1', $rows[2]);
        $this->assertStringContainsString('selected product is invalid', $rows[2], 'the bad product is flagged in its own row');

        // Global summary once, inline once each — never a raw attribute name.
        $this->assertSame(2, substr_count($html, 'must be at least 1'), 'once in the layout summary, once inline');
        $this->assertStringNotContainsString('items.1.quantity', $html);
    }

    public function test_a_refused_mature_release_is_visible(): void
    {
        // Finding 4's real condition: no Mature product in the catalogue, so
        // release has nowhere to put the wheels. The refusal used to back() to
        // wherever the session last was — /stock — which rendered nothing.
        $farmhouse = Product::create([
            'name' => 'Mossfield Farmhouse Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true,
        ]);
        $wheelVariant = ProductVariant::create([
            'product_id' => $farmhouse->id, 'name' => 'Whole Wheel', 'size' => 'wheel', 'unit' => 'wheel',
            'weight_kg' => 2.5, 'base_price' => 35.00, 'is_variable_weight' => true, 'is_active' => true,
        ]);
        $batch = Batch::create([
            'product_id' => $farmhouse->id,
            'production_date' => now()->subMonths(6)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        $wheel = BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $wheelVariant->id,
            'quantity_produced' => 2, 'quantity_remaining' => 2, 'quantity_maturing' => 2, 'unit_weight_kg' => 2.5,
        ]);

        $response = $this->actingAs($this->office)
            ->from(route('stock.index'))
            ->post(route('cheese-conversion.release', $wheel), ['quantity' => 2]);

        $response->assertRedirect(route('cheese-conversion.index'))
            ->assertSessionHasErrors('error');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Mature cheese product is not configured');

        $this->assertSame(2, $wheel->fresh()->quantity_maturing, 'nothing was released');
    }

    public function test_the_feedback_region_is_sticky_and_dismissible(): void
    {
        $html = $this->actingAs($this->office)
            ->withSession(['success' => 'Item quantity updated.'])
            ->get(route('orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Item quantity updated.'));
        $this->assertStringContainsString('class="mf-feedback ', $html);
        $this->assertStringContainsString('aria-label="Dismiss message"', $html);
    }

    public function test_fragment_landings_clear_the_flash_by_its_real_height(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.mf-landing\s*\{\s*scroll-margin-top:\s*calc\(var\(--mf-feedback-h, 0px\) \+ 1rem\)/', $css);
        $this->assertStringContainsString('.mf-landing:target', $css);
        $this->assertStringNotContainsString('scroll-mt-20', $css);

        // No view still uses the fixed 80 px margin.
        $stale = collect(\Illuminate\Support\Facades\File::allFiles(resource_path('views')))
            ->filter(fn ($f) => str_contains($f->getContents(), 'scroll-mt-20'))
            ->map(fn ($f) => $f->getRelativePathname())->values()->all();
        $this->assertSame([], $stale);
    }
}
