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
 * docs/planImp/TESTING_AND_FIXES.md, 2026-09-12 browser pass, finding 9: the cut form and
 * the batch page's "Cut … (N left)" button advertised raw quantity_remaining,
 * so they offered wheels the cut endpoint (correctly) refuses — and the
 * refusal itself was invisible. Both surfaces must read free stock, and a
 * refused cut must land the operator on the form with the reason showing.
 */
class CheeseCutFreeStockTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Product $farmhouse;

    private ProductVariant $wheelVariant;

    private ProductVariant $vacuum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create(); // default role = office

        $this->farmhouse = Product::create([
            'name' => 'Mossfield Farmhouse Cheese',
            'type' => 'cheese',
            'maturation_days' => 90,
            'is_active' => true,
        ]);
        $this->wheelVariant = ProductVariant::create([
            'product_id' => $this->farmhouse->id,
            'name' => 'Whole Wheel',
            'size' => 'wheel',
            'unit' => 'wheel',
            'weight_kg' => 2.500,
            'base_price' => 35.00,
            'is_variable_weight' => true,
            'is_active' => true,
        ]);
        $this->vacuum = ProductVariant::create([
            'product_id' => $this->farmhouse->id,
            'name' => 'Vacuum Pack',
            'size' => 'pack',
            'unit' => 'pack',
            'weight_kg' => 0.250,
            'base_price' => 7.00,
            'is_variable_weight' => true,
            'is_bulk_weighed' => true,
            'is_active' => true,
        ]);
    }

    /** A ready-to-cut farmhouse batch with $wheels wheels, $held of them on maturing hold. */
    private function wheels(int $wheels, int $held = 0): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->farmhouse->id,
            'production_date' => now()->subMonths(6)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $this->wheelVariant->id,
            'quantity_produced' => $wheels,
            'quantity_remaining' => $wheels,
            'quantity_maturing' => $held,
            'unit_weight_kg' => 2.500,
        ]);
    }

    /** Reserve $quantity wheels for an order and pick $picked of them. Returns the allocation. */
    private function reserve(BatchItem $batchItem, int $quantity, int $picked = 0): \App\Models\OrderAllocation
    {
        $shop = Customer::firstOrCreate(
            ['email' => 'cut@example.ie'],
            [
                'name' => 'Cut Shop',
                'address' => '1 Main St',
                'city' => 'Birr',
                'postal_code' => 'R42 AB12',
                'country' => 'Ireland',
                'payment_terms' => 'net_30',
                'is_active' => true,
            ],
        );

        $order = Order::create([
            'customer_id' => $shop->id,
            'order_date' => today()->toDateString(),
            'delivery_date' => today()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->wheelVariant->id,
            'quantity_ordered' => $quantity,
            'unit_price' => $this->wheelVariant->base_price,
        ]);

        $allocation = $item->allocateFromBatchItem($batchItem, $quantity);

        if ($picked > 0) {
            $item->fulfillAllocation($allocation, $picked, $picked * 2.5);
        }

        return $allocation->fresh();
    }

    private function cutPayload(): array
    {
        return [
            'cut_date' => now()->toDateString(),
            'vacuum_pack_variant_id' => $this->vacuum->id,
            'wheel_weight_kg' => 2.5,
            'vacuum_packs_created' => 8,
            'total_weight_kg' => 2.0,
        ];
    }

    /** The "Wheels available" figure as the cut form renders it. */
    private function wheelsAvailableOnForm(BatchItem $item): int
    {
        $html = $this->actingAs($this->office)
            ->get(route('cheese-cutting.create', $item))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/Wheels available<\/dt>\s*<dd[^>]*>\s*(\d+)\s*<\/dd>/', $html);
        preg_match('/Wheels available<\/dt>\s*<dd[^>]*>\s*(\d+)\s*<\/dd>/', $html, $m);

        return (int) $m[1];
    }

    /** The "Wheels left" figure as the cutting index renders it for one batch. */
    private function wheelsLeftOnIndex(Batch $batch): ?int
    {
        $html = $this->actingAs($this->office)
            ->get(route('cheese-cutting.index'))
            ->assertOk()
            ->getContent();

        // Scope to this batch's card: from its code to the next card (or the end).
        $start = strpos($html, $batch->batch_code);
        if ($start === false) {
            return null;
        }
        $card = substr($html, $start);
        $this->assertMatchesRegularExpression('/Wheels left:<\/span>\s*<span[^>]*>\s*(\d+)\s*<\/span>/', $card);
        preg_match('/Wheels left:<\/span>\s*<span[^>]*>\s*(\d+)\s*<\/span>/', $card, $m);

        return (int) $m[1];
    }

    // ---------------------------------------------------------------- cases

    // ----- /cheese-cutting (the index — first screen in the workflow)

    public function test_the_cutting_index_reports_free_wheels_not_raw_remaining(): void
    {
        $partlyHeld = $this->wheels(5, held: 2);

        $this->assertSame(3, $this->wheelsLeftOnIndex($partlyHeld->batch));

        $this->actingAs($this->office)
            ->get(route('cheese-cutting.index'))
            ->assertOk()
            ->assertSee('3 wheels available')
            ->assertDontSee('5 wheels remaining')
            ->assertSee(route('cheese-cutting.create', $partlyHeld));
    }

    public function test_the_cutting_index_does_not_list_a_fully_held_batch_as_ready_to_cut(): void
    {
        $fullyHeld = $this->wheels(5, held: 5);

        $this->actingAs($this->office)
            ->get(route('cheese-cutting.index'))
            ->assertOk()
            ->assertDontSee($fullyHeld->batch->batch_code)
            ->assertDontSee(route('cheese-cutting.create', $fullyHeld))
            ->assertSee('Every ready wheel is spoken for');
    }

    public function test_the_cutting_index_subtracts_reserved_wheels(): void
    {
        $reserved = $this->wheels(5);
        $this->reserve($reserved, 3, picked: 1);

        // 4 physically remain, 2 still owed to the order ⇒ 2 free to cut.
        $this->assertSame(2, $this->wheelsLeftOnIndex($reserved->batch));
    }

    public function test_the_cutting_index_never_gates_the_reservation_on_fulfilled_at(): void
    {
        $reserved = $this->wheels(5);
        $allocation = $this->reserve($reserved, 3, picked: 1);

        // A partially picked allocation that has (for whatever reason) been
        // stamped — the invariant says the sum is quantity_allocated −
        // quantity_fulfilled over ALL allocations, never filtered on the stamp.
        $allocation->forceFill(['fulfilled_at' => now()])->save();

        $this->assertSame(2, $this->wheelsLeftOnIndex($reserved->batch),
            'stamping fulfilled_at must not make the 2 owed wheels look free');
    }

    // ----- /cheese-cutting/cut/{item} and /batches/{batch}

    public function test_the_cut_form_reports_free_wheels_not_raw_remaining(): void
    {
        $this->assertSame(0, $this->wheelsAvailableOnForm($this->wheels(5, held: 5)),
            'with every wheel held the form must offer none');

        $this->assertSame(3, $this->wheelsAvailableOnForm($this->wheels(5, held: 2)));
    }

    public function test_the_batch_page_cut_button_counts_free_wheels(): void
    {
        $partlyHeld = $this->wheels(5, held: 2);

        $this->actingAs($this->office)
            ->get(route('batches.show', $partlyHeld->batch))
            ->assertOk()
            ->assertSee('Cut Whole Wheel')
            ->assertSee('(3 left)')
            ->assertDontSee('(5 left)');
    }

    public function test_the_batch_page_offers_no_cut_when_every_wheel_is_held(): void
    {
        $fullyHeld = $this->wheels(5, held: 5);

        $this->actingAs($this->office)
            ->get(route('batches.show', $fullyHeld->batch))
            ->assertOk()
            ->assertDontSee('Cut Whole Wheel')
            ->assertSee('No wheels available');
    }

    public function test_a_refused_cut_lands_on_the_form_with_the_reason_showing(): void
    {
        $fullyHeld = $this->wheels(5, held: 5);

        // No Referer at all — a forged or scripted POST. back() would have sent
        // the operator to whatever page the session last saw.
        $response = $this->actingAs($this->office)
            ->post(route('cheese-cutting.store', $fullyHeld), $this->cutPayload());

        $response->assertRedirect(route('cheese-cutting.create', $fullyHeld))
            ->assertSessionHasErrors('error');

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('No free wheels remaining to cut');

        $this->assertSame(5, $fullyHeld->fresh()->quantity_remaining, 'nothing was cut');
    }

    public function test_a_refused_cut_from_the_stock_page_still_shows_the_reason(): void
    {
        $fullyHeld = $this->wheels(5, held: 5);

        $response = $this->actingAs($this->office)
            ->from(route('stock.index'))
            ->post(route('cheese-cutting.store', $fullyHeld), $this->cutPayload());

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('No free wheels remaining to cut');
    }

    public function test_a_permitted_cut_still_succeeds_with_a_visible_confirmation(): void
    {
        $partlyHeld = $this->wheels(5, held: 2);

        $response = $this->actingAs($this->office)
            ->post(route('cheese-cutting.store', $partlyHeld), $this->cutPayload());

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Cheese wheel cut successfully');

        $this->assertSame(4, $partlyHeld->fresh()->quantity_remaining);
    }
}
