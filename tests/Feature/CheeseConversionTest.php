<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseConversionLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mature conversion (/cheese-conversion) — the two-phase model.
 * 1. Maturing hold: reversible set-aside of wheels at ANY age, excluded from
 *    order allocation. 2. Release: aged held wheels become the Mature product
 *    (reversible until the mature wheels are cut/sold). Index is readable by
 *    admin/office/factory; hold/release/undo are office/admin only.
 */
class CheeseConversionTest extends TestCase
{
    use RefreshDatabase;

    private Product $farmhouse;

    private Product $mature;

    private ProductVariant $farmhouseWheel;

    private ProductVariant $matureWheel;

    private ProductVariant $matureVacuum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmhouse = Product::create([
            'name' => 'Mossfield Farmhouse Cheese',
            'type' => 'cheese',
            'maturation_days' => 90,
            'is_active' => true,
        ]);
        $this->farmhouseWheel = $this->variant($this->farmhouse, 'Whole Wheel', 'wheel', 2.500, 35.00);

        $this->mature = Product::create([
            'name' => 'Mossfield Mature Cheese',
            'type' => 'cheese',
            'maturation_days' => 150,
            'is_active' => true,
        ]);
        $this->matureWheel = $this->variant($this->mature, 'Whole Wheel', 'wheel', 2.300, 55.00);
        $this->matureVacuum = $this->variant($this->mature, 'Vacuum Pack', 'pack', 0.250, 7.00, true);
    }

    private function variant(Product $product, string $name, string $size, float $weight, float $price, bool $bulk = false): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => $name,
            'size' => $size,
            'unit' => $size,
            'weight_kg' => $weight,
            'base_price' => $price,
            'is_variable_weight' => true,
            'is_bulk_weighed' => $bulk,
            'is_active' => true,
        ]);
    }

    /** A farmhouse batch aged $monthsOld months with $wheels whole wheels. */
    private function farmhouseBatch(int $monthsOld, int $wheels = 5): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->farmhouse->id,
            'production_date' => now()->subMonths($monthsOld)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $this->farmhouseWheel->id,
            'quantity_produced' => $wheels,
            'quantity_remaining' => $wheels,
            'unit_weight_kg' => 2.500,
        ]);
    }

    private function office(): User
    {
        return User::factory()->create(); // default role = office
    }

    /** Set the maturing hold on a wheel item via the controller. */
    private function hold(User $user, BatchItem $wheel, int $qty): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('cheese-conversion.hold', $wheel), ['quantity' => $qty]);
    }

    public function test_index_lists_batches_of_any_age(): void
    {
        $old = $this->farmhouseBatch(6);
        $young = $this->farmhouseBatch(1);

        $this->actingAs($this->office())->get(route('cheese-conversion.index'))
            ->assertOk()
            ->assertSee($old->batch->batch_code)
            ->assertSee($young->batch->batch_code); // young batches can be set aside too
    }

    public function test_hold_sets_aside_wheels_and_reduces_available(): void
    {
        $wheel = $this->farmhouseBatch(2, 5); // young — allowed

        $this->hold($this->office(), $wheel, 3)->assertRedirect(route('cheese-conversion.index'));

        $wheel->refresh();
        $this->assertSame(3, $wheel->quantity_maturing);
        $this->assertSame(5, $wheel->quantity_remaining); // still physical stock
        $this->assertSame(2, $wheel->available_quantity); // held wheels excluded
        $this->assertSame(0, Batch::where('product_id', $this->mature->id)->count()); // no product conversion
    }

    public function test_hold_is_reversible(): void
    {
        $wheel = $this->farmhouseBatch(2, 5);
        $office = $this->office();

        $this->hold($office, $wheel, 4);
        $this->assertSame(4, $wheel->fresh()->quantity_maturing);

        // Lowering the hold returns wheels to available.
        $this->hold($office, $wheel, 1);
        $wheel->refresh();
        $this->assertSame(1, $wheel->quantity_maturing);
        $this->assertSame(4, $wheel->available_quantity);
    }

    public function test_hold_is_capped_at_holdable(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $this->reserve($wheel, 4); // 1 holdable

        $this->hold($this->office(), $wheel, 2)->assertSessionHasErrors('quantity');
        $this->assertSame(0, $wheel->fresh()->quantity_maturing);
    }

    public function test_held_wheels_are_excluded_from_auto_allocation(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 4); // only 1 free

        [$order, $orderItem] = $this->orderFor($this->farmhouseWheel, 5);

        $this->actingAs($office)->post(route('order-allocations.auto-allocate', $order))->assertRedirect();

        // Auto-allocate could only grab the 1 free wheel, not the 4 held.
        $this->assertSame(1, (int) $orderItem->fresh()->quantity_allocated);
    }

    public function test_factory_can_view_index_but_cannot_hold(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $wheel = $this->farmhouseBatch(6);

        $this->actingAs($factory)->get(route('cheese-conversion.index'))->assertOk();
        $this->hold($factory, $wheel, 1)->assertForbidden();
        $this->assertSame(0, $wheel->fresh()->quantity_maturing);
    }

    public function test_driver_is_denied_the_index(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)->get(route('cheese-conversion.index'))->assertForbidden();
    }

    public function test_hold_allowed_on_a_mature_batch_is_rejected(): void
    {
        $matureBatch = Batch::create([
            'product_id' => $this->mature->id,
            'production_date' => now()->subMonths(6)->toDateString(),
            'raw_milk_litres' => 0,
            'status' => 'active',
        ]);
        $matureItem = BatchItem::create([
            'batch_id' => $matureBatch->id,
            'product_variant_id' => $this->matureWheel->id,
            'quantity_produced' => 3,
            'quantity_remaining' => 3,
            'unit_weight_kg' => 2.300,
        ]);

        $this->hold($this->office(), $matureItem, 1)->assertNotFound();
    }

    public function test_release_blocked_when_batch_is_young(): void
    {
        $wheel = $this->farmhouseBatch(2, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);

        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 3])->assertNotFound();
        $this->assertSame(0, Batch::where('product_id', $this->mature->id)->count());
    }

    public function test_release_finds_the_conversion_only_product_even_when_renamed(): void
    {
        // The flag, not the name, marks the destination (OPEN_QUESTIONS #5).
        $this->mature->update(['name' => 'Mossfield Aged Cheese', 'conversion_only' => true]);
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 2);

        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 2])
            ->assertRedirect(route('cheese-conversion.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Batch::where('product_id', $this->mature->id)->count());
        $this->assertSame(2, (int) BatchItem::where('product_variant_id', $this->matureWheel->id)->sum('quantity_remaining'));
    }

    public function test_release_converts_held_wheels_to_mature(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);

        $response = $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 3])
            ->assertRedirect(route('cheese-conversion.index'));

        $wheel->refresh();
        $this->assertSame(2, $wheel->quantity_remaining);  // 3 left the batch
        $this->assertSame(0, $wheel->quantity_maturing);   // hold consumed

        $matureBatch = Batch::where('product_id', $this->mature->id)
            ->where('source_batch_id', $wheel->batch_id)->first();
        $this->assertNotNull($matureBatch);
        $matureItem = $matureBatch->batchItems()->where('product_variant_id', $this->matureWheel->id)->first();
        $this->assertSame(3, $matureItem->quantity_remaining);
        $this->assertTrue($matureBatch->isReadyToSell()); // production carried forward
        $this->assertSame(3, (int) CheeseConversionLog::first()->wheels_converted);

        // The flash says where the wheels went and where the undo lives.
        $flash = session('success');
        $this->assertStringContainsString($matureBatch->batch_code, $flash);
        $this->assertStringContainsString('Undo from', $flash);
        $this->assertStringStartsWith('Released 3 wheels into mature batch', $flash);
    }

    public function test_release_is_hidden_when_mature_cheese_is_not_set_up(): void
    {
        $this->matureVacuum->delete();
        $this->matureWheel->delete();
        $this->mature->delete();
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 2);

        $this->actingAs($office)->get(route('cheese-conversion.index'))
            ->assertOk()
            ->assertSee(\App\Http\Controllers\CheeseConversionController::MATURE_NOT_SET_UP)
            ->assertSee(route('products.create'), false)
            ->assertDontSee('release-form-', false);

        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 2])
            ->assertRedirect(route('cheese-conversion.index'))
            ->assertSessionHasErrors(['error' => \App\Http\Controllers\CheeseConversionController::MATURE_NOT_SET_UP]);
        $this->assertSame(2, $wheel->fresh()->quantity_maturing);

        // Factory can't release anyway: neither the strip nor the button.
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('cheese-conversion.index'))
            ->assertOk()
            ->assertDontSee('data-mature-not-set-up', false)
            ->assertDontSee('release-form-', false);
    }

    public function test_release_with_no_hold_is_rejected(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);

        $this->actingAs($this->office())->post(route('cheese-conversion.release', $wheel), ['quantity' => 1])
            ->assertSessionHasErrors();
        $this->assertSame(0, Batch::where('product_id', $this->mature->id)->count());
    }

    /**
     * Finding 4 (2026-09-12 browser pass): release() ignored `quantity`
     * entirely — 2, 50 and −3 all released the whole hold. It is now bounded
     * exactly the way hold() is: 1 ≤ quantity ≤ the saved hold.
     */
    public function test_release_is_bounded_by_the_saved_hold(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 2);

        foreach ([50, 3, 0, -3, 'two'] as $bad) {
            $this->actingAs($office)
                ->post(route('cheese-conversion.release', $wheel), ['quantity' => $bad])
                ->assertRedirect()
                ->assertSessionHasErrors('quantity');
        }

        $this->actingAs($office)
            ->post(route('cheese-conversion.release', $wheel), [])
            ->assertSessionHasErrors('quantity');

        $wheel->refresh();
        $this->assertSame(2, $wheel->quantity_maturing, 'nothing was released by an out-of-range quantity');
        $this->assertSame(5, $wheel->quantity_remaining);
        $this->assertSame(0, CheeseConversionLog::count());
    }

    public function test_over_release_is_refused_with_the_same_message_as_over_hold(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 2);

        $response = $this->actingAs($office)
            ->post(route('cheese-conversion.release', $wheel), ['quantity' => 50]);

        $response->assertRedirect(route('cheese-conversion.index'));
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('must not be greater than 2');
    }

    public function test_release_can_be_partial(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);

        $this->actingAs($office)
            ->post(route('cheese-conversion.release', $wheel), ['quantity' => 1])
            ->assertRedirect(route('cheese-conversion.index'))
            ->assertSessionHasNoErrors();

        $wheel->refresh();
        $this->assertSame(4, $wheel->quantity_remaining, 'one wheel left the batch');
        $this->assertSame(2, $wheel->quantity_maturing, 'two are still held');
        $this->assertSame(1, (int) CheeseConversionLog::first()->wheels_converted);
        $this->assertSame(1, (int) BatchItem::where('product_variant_id', $this->matureWheel->id)->sum('quantity_remaining'));
    }

    public function test_the_release_button_posts_exactly_the_saved_hold(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);

        $html = $this->actingAs($office)->get(route('cheese-conversion.index'))->assertOk()->getContent();

        // A dedicated release form whose quantity defaults to the saved hold —
        // not the allocator's live (possibly unsaved) Maturing-zone count.
        // Since cycle 4 (F-13) the count is an editable input in the card
        // header, tied to that form by form=, and the button is off while the
        // Maturing zone differs from the saved hold.
        $releaseUrl = preg_quote(route('cheese-conversion.release', $wheel), '/');
        $this->assertMatchesRegularExpression('/<form id="release-form-'.$wheel->id.'"[^>]*action="'.$releaseUrl.'"/', $html);
        $this->assertMatchesRegularExpression(
            '/<input type="number"[^>]*name="quantity" form="release-form-'.$wheel->id.'"[^>]*max="3" value="3"/s',
            $html,
        );
        $this->assertStringContainsString(':disabled="mature.length !== 3"', $html);
        $this->assertStringContainsString('of 3 to mature', $html);
        $this->assertStringContainsString('Save maturing first', $html);
    }

    public function test_part_of_a_hold_can_be_released_from_the_page(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 2);

        $this->actingAs($office)->get(route('cheese-conversion.index'))
            ->assertOk()
            ->assertSee('min="1" max="2" value="2"', false);

        $this->actingAs($office)
            ->post(route('cheese-conversion.release', $wheel), ['quantity' => 1])
            ->assertRedirect(route('cheese-conversion.index'))
            ->assertSessionHasNoErrors();

        $wheel->refresh();
        $this->assertSame(1, $wheel->quantity_maturing, 'one wheel still held');
        $this->assertSame(1, (int) CheeseConversionLog::first()->wheels_converted);
    }

    public function test_undo_release_returns_wheels_to_the_hold(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);
        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 3]);

        $log = CheeseConversionLog::first();
        $matureBatch = Batch::where('product_id', $this->mature->id)->sole();
        $this->actingAs($office)
            ->from(route('batches.show', $matureBatch))
            ->post(route('cheese-conversion.undo', $log))
            ->assertRedirect(route('batches.show', $wheel->batch_id))
            ->assertSessionHas('success', "Returned 3 wheels to the farmhouse maturing hold. Batch {$matureBatch->batch_code} held nothing else and was removed.");

        $wheel->refresh();
        $this->assertSame(5, $wheel->quantity_remaining);  // wheels back
        $this->assertSame(3, $wheel->quantity_maturing);   // back into the hold
        $this->assertNull(CheeseConversionLog::find($log->id));

        // The mature batch held nothing else: it is gone, not a 0-unit ghost.
        $this->assertNull(Batch::find($matureBatch->id));
        $this->assertSame(0, BatchItem::where('product_variant_id', $this->matureWheel->id)->count());

        $this->actingAs($office)->get(route('dashboard')); // consumes the flash, which names the code
        $this->actingAs($office)->get(route('batches.index'))->assertOk()->assertDontSee($matureBatch->batch_code);
        $this->actingAs($office)->get(route('stock.index'))->assertOk()->assertDontSee($matureBatch->batch_code);
    }

    public function test_undo_keeps_a_mature_batch_that_still_holds_history(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);
        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 3]);

        $matureItem = BatchItem::where('product_variant_id', $this->matureWheel->id)->sole();
        $writeOffs = app(\App\Services\StockWriteOffService::class);
        $writeOff = $writeOffs->record(
            $matureItem, \App\Models\WriteOffReason::where('code', 'spoiled')->firstOrFail(), 1, null, null, today(), $office,
        );
        $writeOffs->reverse($writeOff, $office);
        $this->assertSame(3, $matureItem->fresh()->available_quantity);

        $matureBatch = $matureItem->batch;
        $this->actingAs($office)
            ->from(route('batches.show', $matureBatch))
            ->post(route('cheese-conversion.undo', CheeseConversionLog::sole()))
            ->assertRedirect(route('batches.show', $matureBatch))
            ->assertSessionHas('success', 'Returned 3 wheels to the farmhouse maturing hold.');

        $this->assertSame(5, $wheel->fresh()->quantity_remaining);
        $kept = Batch::find($matureBatch->id);
        $this->assertNotNull($kept, 'a reversed write-off is history: the batch stays');
        $this->assertSame(0, (int) $kept->batchItems()->sum('quantity_produced'));

        // The converted item's unit weight says where it came from; a farmhouse batch doesn't.
        $this->actingAs($office)->get(route('batches.show', $kept))->assertOk()->assertSee('nominal from the source wheel');
        $this->actingAs($office)->get(route('batches.show', $wheel->batch_id))->assertOk()->assertDontSee('nominal from the source wheel');
    }

    public function test_a_second_release_after_undo_recreates_the_batch_with_the_same_code(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 2);
        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 2]);
        $firstCode = Batch::where('product_id', $this->mature->id)->sole()->batch_code;

        $this->actingAs($office)->post(route('cheese-conversion.undo', CheeseConversionLog::sole()));
        $this->assertSame(0, Batch::where('product_id', $this->mature->id)->count());

        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 2])
            ->assertSessionHasNoErrors();

        $again = Batch::where('product_id', $this->mature->id)->sole();
        $this->assertSame($firstCode, $again->batch_code);
        $this->assertSame($wheel->batch_id, $again->source_batch_id);
        $this->assertSame(1, CheeseConversionLog::count());
    }

    public function test_undo_release_blocked_once_mature_wheels_are_cut(): void
    {
        $wheel = $this->farmhouseBatch(6, 5);
        $office = $this->office();
        $this->hold($office, $wheel, 3);
        $this->actingAs($office)->post(route('cheese-conversion.release', $wheel), ['quantity' => 3]);

        $matureItem = BatchItem::where('product_variant_id', $this->matureWheel->id)->first();
        // Cut all 3 mature wheels away.
        $this->actingAs($office)->post(route('cheese-cutting.store', $matureItem), [
            'cut_date' => now()->toDateString(),
            'vacuum_pack_variant_id' => $this->matureVacuum->id,
            'vacuum_packs_created' => 24,
            'total_weight_kg' => 6.000,
            'notes' => 'cut',
        ]);
        // Re-cut to drain remaining (cut decrements 1 per call); cut the other 2.
        $this->actingAs($office)->post(route('cheese-cutting.store', $matureItem->fresh()), [
            'cut_date' => now()->toDateString(),
            'vacuum_pack_variant_id' => $this->matureVacuum->id,
            'wheel_weight_kg' => 2.5, 'vacuum_packs_created' => 8, 'total_weight_kg' => 2.0, 'notes' => 'cut',
        ]);
        $this->actingAs($office)->post(route('cheese-cutting.store', $matureItem->fresh()), [
            'cut_date' => now()->toDateString(),
            'vacuum_pack_variant_id' => $this->matureVacuum->id,
            'wheel_weight_kg' => 2.5, 'vacuum_packs_created' => 8, 'total_weight_kg' => 2.0, 'notes' => 'cut',
        ]);

        $log = CheeseConversionLog::first();
        $this->actingAs($office)->post(route('cheese-conversion.undo', $log))->assertSessionHasErrors('error');
        $this->assertNotNull(CheeseConversionLog::find($log->id)); // log survives
    }

    public function test_stock_overview_shows_maturing_segment_and_excludes_from_available(): void
    {
        $wheel = $this->farmhouseBatch(2, 5);
        $this->hold($this->office(), $wheel, 2);

        $card = (new \App\Services\StockOverviewService)->build()['cheese'];
        $row = collect($card['wheels'])->firstWhere('batch_code', $wheel->batch->batch_code);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['segments']['maturing']);
        $this->assertSame(3, $row['segments']['available']); // 5 − 2 held
    }

    public function test_held_wheels_cannot_be_cut(): void
    {
        $wheel = $this->farmhouseBatch(6, 2);
        $office = $this->office();
        $this->hold($office, $wheel, 2); // all held

        $this->actingAs($office)->post(route('cheese-cutting.store', $wheel), [
            'cut_date' => now()->toDateString(),
            'vacuum_pack_variant_id' => $this->matureVacuum->id, // any vac variant
            'wheel_weight_kg' => 2.5, 'vacuum_packs_created' => 8,
            'total_weight_kg' => 2.0,
            'notes' => 'should fail',
        ])->assertSessionHasErrors('error');

        $this->assertSame(2, $wheel->fresh()->quantity_remaining); // unchanged
    }

    public function test_the_allocator_never_gates_the_reservation_on_fulfilled_at(): void
    {
        // 5 wheels; an order reserved 3 and 1 has been picked (remaining 4).
        // The pick stamped fulfilled_at, but 2 wheels are still owed.
        $wheel = $this->farmhouseBatch(6, 5);
        [, $orderItem] = $this->orderFor($this->farmhouseWheel, 3);
        \App\Models\OrderAllocation::create([
            'order_item_id' => $orderItem->id, 'batch_item_id' => $wheel->id,
            'quantity_allocated' => 3, 'quantity_fulfilled' => 1,
            'allocated_at' => now(), 'fulfilled_at' => now(),
        ]);
        $wheel->update(['quantity_remaining' => 4]);
        $office = $this->office();

        // holdable = 4 remaining − 2 still owed = 2 (it showed 4 while gated).
        $this->actingAs($office)->get(route('cheese-conversion.index'))
            ->assertOk()
            ->assertSee('matureCard(2, 0)', false)
            ->assertDontSee('matureCard(4, 0)', false);

        // The server-side cap agrees with the page.
        $this->assertSame(2, $wheel->fresh()->holdable_quantity);
        $this->hold($office, $wheel, 3)->assertSessionHasErrors('quantity');
        $this->assertSame(0, (int) $wheel->fresh()->quantity_maturing);
    }

    /** Build an order with one unallocated line for the given variant. */
    private function orderFor(ProductVariant $variant, int $qty): array
    {
        $customer = Customer::create([
            'name' => 'Order Customer',
            'email' => uniqid().'@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67',
            'is_active' => true,
        ]);
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => $qty,
            'quantity_allocated' => 0,
            'unit_price' => 35.00,
        ]);

        return [$order, $orderItem];
    }

    /** Reserve $qty wheels of $item via an order allocation (not fulfilled). */
    private function reserve(BatchItem $item, int $qty): void
    {
        $customer = Customer::create([
            'name' => 'Test Customer',
            'email' => uniqid().'@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'is_active' => true,
        ]);
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity_ordered' => $qty,
            'quantity_allocated' => $qty,
            'unit_price' => 35.00,
        ]);
        OrderAllocation::create([
            'order_item_id' => $orderItem->id,
            'batch_item_id' => $item->id,
            'quantity_allocated' => $qty,
            'quantity_fulfilled' => 0,
            'allocated_at' => now(),
        ]);
    }
}
