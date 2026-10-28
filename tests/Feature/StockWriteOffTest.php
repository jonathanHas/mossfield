<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockWriteOff;
use App\Models\User;
use App\Models\WriteOffReason;
use App\Services\StockException;
use App\Services\StockOverviewService;
use App\Services\StockWriteOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock write-offs (OPEN_QUESTIONS #1): lost units leave through
 * quantity_remaining — so every free-stock surface drops them at once — and
 * stay on the record, reversibly.
 */
class StockWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private Product $milk;

    private ProductVariant $bottle;

    private Product $cheese;

    private ProductVariant $wheel;

    private User $office;

    private StockWriteOffService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milk = Product::create(['name' => 'Test Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $this->milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.00, 'case_size' => 16, 'is_active' => true,
        ]);
        $this->cheese = Product::create(['name' => 'Test Farmhouse', 'type' => 'cheese', 'maturation_days' => 0, 'is_active' => true]);
        $this->wheel = ProductVariant::create([
            'product_id' => $this->cheese->id, 'name' => 'Whole Wheel', 'size' => 'wheel', 'unit' => 'wheel',
            'weight_kg' => 5.0, 'base_price' => 16.00, 'is_active' => true, 'is_variable_weight' => true,
        ]);
        $this->office = User::factory()->create();
        $this->service = app(StockWriteOffService::class);
    }

    private function item(ProductVariant $variant, int $qty, array $batch = [], array $item = []): BatchItem
    {
        $b = Batch::create(array_merge([
            'product_id' => $variant->product_id,
            'production_date' => today()->subDays(3),
            'expiry_date' => today()->addDays(7),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ], $batch));

        return BatchItem::create(array_merge([
            'batch_id' => $b->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => $qty, 'quantity_remaining' => $qty, 'unit_weight_kg' => $variant->weight_kg,
        ], $item));
    }

    private function reason(string $code = 'spoiled'): WriteOffReason
    {
        return WriteOffReason::where('code', $code)->firstOrFail();
    }

    private function writeOff(BatchItem $item, int $qty, ?float $kg = null, string $reason = 'spoiled'): StockWriteOff
    {
        return $this->service->record($item, $this->reason($reason), $qty, $kg, null, today(), $this->office);
    }

    /** Reserve $qty of $item on a confirmed order line (picked $picked). */
    private function reserve(BatchItem $item, int $qty, int $picked = 0): OrderItem
    {
        $customer = Customer::create([
            'name' => 'Shop '.uniqid(), 'email' => uniqid().'@example.ie',
            'address' => 'Main St', 'city' => 'Bray', 'postal_code' => 'A98', 'is_active' => true,
        ]);
        $order = Order::create([
            'customer_id' => $customer->id, 'order_date' => today(), 'delivery_date' => today(),
            'status' => 'confirmed', 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $line = $order->orderItems()->create([
            'product_variant_id' => $item->product_variant_id, 'quantity_ordered' => $qty, 'unit_price' => 1,
        ]);
        OrderAllocation::create([
            'order_item_id' => $line->id, 'batch_item_id' => $item->id,
            'quantity_allocated' => $qty, 'quantity_fulfilled' => $picked, 'allocated_at' => now(),
        ]);
        if ($picked) {
            $item->decrement('quantity_remaining', $picked);
        }

        return $line;
    }

    // ---------------------------------------------------------------- the service

    public function test_seven_default_reasons_ship_with_the_migration(): void
    {
        $this->assertSame(
            ['spoiled', 'damaged', 'sample', 'own_use', 'stocktake', 'expired', 'returned'],
            WriteOffReason::ordered()->pluck('code')->all()
        );
    }

    public function test_written_off_units_leave_every_free_stock_surface(): void
    {
        $bottles = $this->item($this->bottle, 20);
        $wheels = $this->item($this->wheel, 5);

        $this->writeOff($bottles, 3);
        $this->writeOff($wheels, 2);

        // 1. The model accessor every allocation path reads.
        $this->assertSame(17, $bottles->fresh()->available_quantity);
        // 2. The bulk form behind /products and the mossorders export.
        $this->assertSame(17, BatchItem::freeStockByVariant([$this->bottle->id])[$this->bottle->id]);
        // 3. /stock's available segment.
        $this->assertSame(17, app(StockOverviewService::class)->build()['milk']['variants'][0]['segments']['available']);
        // 4. The cutting index's "Wheels left".
        $html = $this->actingAs($this->office)->get(route('cheese-cutting.index'))->assertOk()->getContent();
        $card = substr($html, strpos($html, $wheels->batch->batch_code));
        preg_match('/Wheels left:<\/span>\s*<span[^>]*>\s*(\d+)\s*<\/span>/', $card, $m);
        $this->assertSame('3', $m[1] ?? null);
        // 5. The picking batch chooser.
        $line = $this->reserveNothing($this->bottle, 20);
        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('picking.item', [$line->order, $line]))
            ->assertOk()
            ->assertViewHas('batchOptions', fn ($options) => $options->firstWhere(fn ($o) => $o['batchItem']->id === $bottles->id)['max'] === 17);
    }

    /** An unallocated confirmed line for $variant. */
    private function reserveNothing(ProductVariant $variant, int $qty): OrderItem
    {
        $line = $this->reserve($this->item($variant, 1), 1);
        $line->orderAllocations()->delete();
        $line->update(['quantity_ordered' => $qty, 'quantity_allocated' => 0]);

        return $line->fresh();
    }

    public function test_reserved_units_cannot_be_written_off(): void
    {
        $item = $this->item($this->bottle, 10);
        $this->reserve($item, 6);

        try {
            $this->writeOff($item, 5);
            $this->fail('expected a refusal');
        } catch (StockException $e) {
            $this->assertSame('Only 4 units can be written off — 6 are reserved for orders. Release them first.', $e->getMessage());
        }
        $this->assertSame(10, $item->fresh()->quantity_remaining);

        $this->writeOff($item, 4);
        $this->assertSame(6, $item->fresh()->quantity_remaining);
    }

    public function test_writing_off_held_wheels_clamps_the_hold(): void
    {
        $item = $this->item($this->wheel, 5, item: ['quantity_maturing' => 4]);

        $row = $this->writeOff($item, 3);

        $this->assertSame(2, $item->fresh()->quantity_remaining);
        $this->assertSame(2, $item->fresh()->quantity_maturing, 'hold clamped to what remains');
        $this->assertSame(2, $row->holdReducedTo);
    }

    public function test_weight_is_refused_on_unweighed_stock_and_bounded_on_weighed(): void
    {
        $bottles = $this->item($this->bottle, 10);
        $this->expectRefusal(fn () => $this->writeOff($bottles, 1, 1.0), "1L Bottle isn't weighed — leave the weight blank.");

        $wheels = $this->item($this->wheel, 5);
        $this->expectRefusal(fn () => $this->writeOff($wheels, 1, 49.0), 'Check the decimal point.');
        $this->assertSame('4.900', $this->writeOff($wheels, 1, 4.9)->weight_kg);
    }

    public function test_an_inactive_reason_is_refused(): void
    {
        $item = $this->item($this->bottle, 10);
        $this->reason('sample')->update(['is_active' => false]);

        $this->expectRefusal(fn () => $this->writeOff($item, 1, null, 'sample'), 'no longer in use');
    }

    public function test_the_date_must_fall_between_production_and_today(): void
    {
        $item = $this->item($this->bottle, 10);

        $this->expectRefusal(fn () => $this->service->record($item, $this->reason(), 1, null, null, today()->subDays(10), $this->office), 'before the batch was produced');
        $this->expectRefusal(fn () => $this->service->record($item, $this->reason(), 1, null, null, today()->addDay(), $this->office), 'in the future');
        $this->assertSame(10, $item->fresh()->quantity_remaining);
    }

    public function test_reverse_restores_and_stamps_once(): void
    {
        $item = $this->item($this->bottle, 10);
        $row = $this->writeOff($item, 4);

        $this->service->reverse($row, $this->office);

        $this->assertSame(10, $item->fresh()->quantity_remaining);
        $this->assertNotNull($row->fresh()->reversed_at);
        $this->assertSame($this->office->id, $row->fresh()->reversed_by);
        $this->assertSame(0, $item->fresh()->written_off_units, 'a reversed write-off is not a write-off');

        $this->expectRefusal(fn () => $this->service->reverse($row->fresh(), $this->office), 'already reversed');
        $this->assertSame(10, $item->fresh()->quantity_remaining);
    }

    public function test_write_off_expired_takes_every_item_and_refuses_a_fresh_batch(): void
    {
        $a = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()]);
        $b = BatchItem::create([
            'batch_id' => $a->batch_id, 'product_variant_id' => ProductVariant::create([
                'product_id' => $this->milk->id, 'name' => '2L Bottle', 'size' => '2L', 'unit' => 'bottle',
                'weight_kg' => 2.0, 'base_price' => 3.00, 'is_active' => true,
            ])->id,
            'quantity_produced' => 6, 'quantity_remaining' => 6, 'unit_weight_kg' => 2.0,
        ]);
        $this->reserve($b, 2);

        $rows = $this->service->writeOffExpired($a->batch, null, $this->office);

        $this->assertCount(2, $rows);
        $this->assertSame(0, $a->fresh()->quantity_remaining);
        $this->assertSame(2, $b->fresh()->quantity_remaining, 'the reserved units stay');
        $this->assertTrue($rows->every(fn ($r) => $r->reason->code === 'expired'));

        $fresh = $this->item($this->bottle, 5);
        $this->expectRefusal(fn () => $this->service->writeOffExpired($fresh->batch, null, $this->office), "hasn't expired");
    }

    public function test_quantity_sold_excludes_write_offs(): void
    {
        $item = $this->item($this->bottle, 10);
        $item->decrement('quantity_remaining', 3); // sold 3
        $this->writeOff($item->fresh(), 2);

        $item = $item->fresh();
        $this->assertSame(2, $item->written_off_units);
        $this->assertSame(3, $item->quantity_sold);
    }

    // ---------------------------------------------------------------- the fifth state on screen

    public function test_the_batches_card_shows_written_off_wheels(): void
    {
        $item = $this->item($this->wheel, 6);
        $this->writeOff($item, 2);

        $html = $this->actingAs($this->office)->get(route('batches.index'))->assertOk()->getContent();
        $card = substr($html, strpos($html, $item->batch->batch_code));

        $this->assertStringContainsString('2 written off', $card);
        $this->assertSame(2, substr_count($card, 'background: var(--state-written-off);'));
        $this->assertStringContainsString('4 free', $card);
    }

    // ---------------------------------------------------------------- routes

    private function payload(BatchItem $item, array $over = []): array
    {
        return array_merge([
            'batch_item_id' => $item->id,
            'write_off_reason_id' => $this->reason('damaged')->id,
            'quantity' => 2,
            'written_off_on' => today()->toDateString(),
        ], $over);
    }

    public function test_office_records_and_reverses_through_the_routes(): void
    {
        $item = $this->item($this->bottle, 10);

        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $this->payload($item, ['note' => 'Crate dropped']))
            ->assertRedirect(route('batches.show', $item->batch).'#write-offs')
            ->assertSessionHas('success', 'Written off 2 × 1L Bottle — Damaged / dropped.');

        $row = StockWriteOff::sole();
        $this->assertSame('Crate dropped', $row->note);
        $this->assertSame($this->office->id, $row->written_off_by);
        $this->assertSame(8, $item->fresh()->quantity_remaining);

        $this->actingAs($this->office)
            ->post(route('write-offs.reverse', $row))
            ->assertRedirect(route('batches.show', $item->batch).'#write-offs')
            ->assertSessionHas('success', 'Write-off reversed — 2 units returned to free stock.');
        $this->assertSame(10, $item->fresh()->quantity_remaining);
    }

    public function test_a_repeat_submission_of_the_same_form_is_refused(): void
    {
        $item = $this->item($this->bottle, 10);
        $payload = $this->payload($item, ['form_token' => (string) \Illuminate\Support\Str::uuid()]);

        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $payload)
            ->assertSessionHas('success', 'Written off 2 × 1L Bottle — Damaged / dropped.');

        // Back + submit: the browser re-posts the same rendered form.
        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $payload)
            ->assertRedirect(route('batches.show', $item->batch).'#write-offs')
            ->assertSessionHas('success', 'Already recorded — this was a repeat submission.');

        $this->assertSame(1, StockWriteOff::count());
        $this->assertSame(8, $item->fresh()->quantity_remaining);

        // A fresh form (new token) is a new write-off; no token still works.
        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $this->payload($item, ['form_token' => (string) \Illuminate\Support\Str::uuid()]));
        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $this->payload($item));
        $this->assertSame(3, StockWriteOff::count());
        $this->assertSame(4, $item->fresh()->quantity_remaining);
    }

    public function test_the_form_carries_a_token(): void
    {
        $item = $this->item($this->bottle, 10);

        $this->actingAs($this->office)->get(route('batches.show', $item->batch))
            ->assertOk()
            ->assertSee('name="form_token"', false);
    }

    public function test_a_refusal_comes_back_to_the_panel_with_the_message(): void
    {
        $item = $this->item($this->bottle, 10);
        $this->reserve($item, 9);

        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $this->payload($item, ['quantity' => 3]))
            ->assertRedirect(route('batches.show', $item->batch).'#write-offs')
            ->assertSessionHasErrors(['write_off' => 'Only 1 units can be written off — 9 are reserved for orders. Release them first.']);
        $this->assertSame(0, StockWriteOff::count());
    }

    public function test_an_item_from_another_batch_is_refused(): void
    {
        $mine = $this->item($this->bottle, 10);
        $other = $this->item($this->bottle, 10);

        $this->actingAs($this->office)
            ->postJson(route('batches.write-offs.store', $mine->batch), $this->payload($other))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['batch_item_id' => 'That item belongs to another batch.']);
        $this->assertSame(10, $other->fresh()->quantity_remaining);
    }

    public function test_the_expired_route_writes_off_and_names_what_it_kept(): void
    {
        $item = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()]);
        $this->reserve($item, 3);

        $this->actingAs($this->office)
            ->post(route('batches.write-offs.expired', $item->batch))
            ->assertRedirect(route('batches.show', $item->batch).'#write-offs')
            ->assertSessionHas('success', 'Wrote off expired stock: 7 × 1L Bottle. Kept (reserved for orders): 3 × 1L Bottle.');

        $this->assertSame(3, $item->fresh()->quantity_remaining);
        $this->assertSame('Expired — written off from the batch page', StockWriteOff::sole()->note);
    }

    public function test_factory_and_driver_cannot_write_off_or_reverse(): void
    {
        $item = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()]);
        $row = $this->writeOff($item, 1);

        foreach ([User::factory()->factoryWorker()->create(), User::factory()->driver()->create()] as $user) {
            $this->actingAs($user)->post(route('batches.write-offs.store', $item->batch), $this->payload($item))->assertForbidden();
            $this->actingAs($user)->post(route('batches.write-offs.expired', $item->batch))->assertForbidden();
            $this->actingAs($user)->post(route('write-offs.reverse', $row))->assertForbidden();
        }
        $this->assertSame(9, $item->fresh()->quantity_remaining);
        $this->assertNull($row->fresh()->reversed_at);
    }

    // ---------------------------------------------------------------- reasons

    public function test_used_counts_active_write_offs_and_names_reversed_ones(): void
    {
        $item = $this->item($this->bottle, 10);
        $this->service->reverse($this->writeOff($item, 1, null, 'spoiled'), $this->office);
        $this->writeOff($item, 1, null, 'damaged');

        $html = $this->actingAs($this->office)->get(route('write-off-reasons.index'))->assertOk()->getContent();
        $row = function (string $code) use ($html): string {
            $id = $this->reason($code)->id;
            preg_match('/id="reason-'.$id.'".*?<\/tr>/s', $html, $m);

            return preg_replace('/\s+/', ' ', strip_tags($m[0] ?? ''));
        };

        $this->assertMatchesRegularExpression('/ 0 \+ 1 reversed /', $row('spoiled'));
        $this->assertMatchesRegularExpression('/ 1 Active/', $row('damaged'));
        $this->assertStringNotContainsString('reversed', $row('damaged'));
    }

    public function test_office_adds_renames_and_deactivates_reasons(): void
    {
        $this->actingAs($this->office)->get(route('write-off-reasons.index'))
            ->assertOk()->assertSee('Spoiled / mouldy')->assertSee('Add reason');

        $this->actingAs($this->office)->post(route('write-off-reasons.store'), ['label' => 'Broken seal'])
            ->assertRedirect(route('write-off-reasons.index'))->assertSessionHas('success', 'Reason "Broken seal" added.');
        $reason = WriteOffReason::where('label', 'Broken seal')->sole();
        $this->assertSame('broken_seal', $reason->code);
        $this->assertTrue($reason->is_active);
        $this->assertSame(80, $reason->sort_order);

        $this->actingAs($this->office)->post(route('write-off-reasons.store'), ['label' => 'Broken seal'])
            ->assertSessionHasErrors('label');

        $this->actingAs($this->office)->patch(route('write-off-reasons.update', $reason), ['label' => 'Broken seal / leak'])
            ->assertSessionHas('success', 'Reason renamed to "Broken seal / leak".');
        $this->assertSame('broken_seal', $reason->fresh()->code, 'the code is stable');

        $this->actingAs($this->office)->patch(route('write-off-reasons.update', $reason), ['is_active' => 0])
            ->assertSessionHas('success', '"Broken seal / leak" deactivated — it stays on past write-offs.');
        $this->assertFalse($reason->fresh()->is_active);

        // A deactivated reason is refused by the request and the service.
        $item = $this->item($this->bottle, 10);
        $this->actingAs($this->office)
            ->post(route('batches.write-offs.store', $item->batch), $this->payload($item, ['write_off_reason_id' => $reason->id]))
            ->assertSessionHasErrors(['write_off_reason_id' => 'Choose a reason that is still in use.']);
        $this->expectRefusal(fn () => $this->service->record($item, $reason->fresh(), 1, null, null, today(), $this->office), 'no longer in use');

        $this->actingAs($this->office)->patch(route('write-off-reasons.update', $reason), ['is_active' => 1])
            ->assertSessionHas('success', '"Broken seal / leak" is back in use.');
    }

    public function test_factory_cannot_manage_reasons_and_there_is_no_delete(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $reason = $this->reason();

        $this->actingAs($factory)->get(route('write-off-reasons.index'))->assertForbidden();
        $this->actingAs($factory)->post(route('write-off-reasons.store'), ['label' => 'Nope'])->assertForbidden();
        $this->actingAs($factory)->patch(route('write-off-reasons.update', $reason), ['is_active' => 0])->assertForbidden();
        $this->assertTrue($reason->fresh()->is_active);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('write-off-reasons.destroy'));
    }

    // ---------------------------------------------------------------- the batch page

    public function test_the_panel_has_the_form_for_office_and_only_the_history_for_factory(): void
    {
        $item = $this->item($this->wheel, 5);
        $row = $this->writeOff($item, 1, 4.9);
        WriteOffReason::where('code', 'sample')->update(['is_active' => false]);

        $html = $this->actingAs($this->office)->get(route('batches.show', $item->batch))
            ->assertOk()
            ->assertSee('id="write-offs"', false)
            ->assertSee(route('batches.write-offs.store', $item->batch), false)
            ->assertSee('Spoiled / mouldy')
            ->assertSee('Measured weight of these')
            ->assertSee('data-write-off="'.$row->id.'"', false)
            ->assertSee('4.900')
            ->assertSee(route('write-offs.reverse', $row), false)
            ->assertSee('Manage reasons')
            ->getContent();
        $select = substr($html, strpos($html, 'id="wo-reason"'));
        $select = substr($select, 0, strpos($select, '</select>'));
        $this->assertStringNotContainsString('Sample / tasting', $select, 'a deactivated reason is not offered');

        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('batches.show', $item->batch))
            ->assertOk()
            ->assertSee('data-write-off="'.$row->id.'"', false)
            ->assertDontSee(route('batches.write-offs.store', $item->batch), false)
            ->assertDontSee(route('write-offs.reverse', $row), false);
    }

    public function test_a_written_off_row_shows_and_a_reversed_one_is_struck_through(): void
    {
        $item = $this->item($this->bottle, 10);
        $this->actingAs($this->office)->post(route('batches.write-offs.store', $item->batch), $this->payload($item, ['quantity' => 3]));
        $row = StockWriteOff::sole();

        $this->actingAs($this->office)->get(route('batches.show', $item->batch))
            ->assertOk()
            ->assertSeeInOrder(['Written off', '3 units'])
            ->assertSee('data-confirm-title="Reverse this write-off?"', false)
            ->assertSee('3 units go back into free stock.')
            // The variant table: 10 produced, 7 on hand, 0 sold, 3 written off.
            ->assertSeeInOrder(['1L Bottle', '10', '7', '0', '—', '—', '3']);

        $this->actingAs($this->office)->post(route('write-offs.reverse', $row));

        $this->actingAs($this->office)->get(route('batches.show', $item->batch))
            ->assertOk()
            ->assertSee('reversed '.today()->format('d/m/Y').' by '.$this->office->name)
            ->assertSee('line-through', false)
            ->assertDontSee('Reverse this write-off?');
    }

    public function test_the_expired_button_appears_only_on_an_expired_batch_with_stock(): void
    {
        $expired = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()]);
        $fresh = $this->item($this->bottle, 10);
        $emptyExpired = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()], item: ['quantity_remaining' => 0]);

        $this->actingAs($this->office)->get(route('batches.show', $expired->batch))
            ->assertOk()->assertSee('Write off expired stock (10 units)');
        $this->actingAs($this->office)->get(route('batches.show', $fresh->batch))
            ->assertOk()->assertDontSee('Write off expired stock');
        $this->actingAs($this->office)->get(route('batches.show', $emptyExpired->batch))
            ->assertOk()->assertDontSee('Write off expired stock');
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('batches.show', $expired->batch))
            ->assertOk()->assertDontSee('Write off expired stock');

        $this->actingAs($this->office)->post(route('batches.write-offs.expired', $expired->batch));
        $this->assertSame(0, $expired->fresh()->quantity_remaining);
        $this->actingAs($this->office)->get(route('batches.show', $expired->batch))
            ->assertOk()->assertDontSee('Write off expired stock');
    }

    public function test_an_expired_card_links_to_the_panel_and_write_offs_block_deletion(): void
    {
        $expired = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()]);

        $html = $this->actingAs($this->office)->get(route('batches.index'))->assertOk()->getContent();
        $card = substr($html, strpos($html, $expired->batch->batch_code));
        $this->assertStringContainsString(route('batches.show', $expired->batch).'#write-offs', substr($card, 0, 3000));

        $row = $this->writeOff($expired, 1);
        $this->service->reverse($row, $this->office);

        // Reversed rows count too: they're history.
        $this->assertStringContainsString('1 write-off record', $expired->batch->fresh()->deletionBlocker());
        $this->actingAs(User::factory()->admin()->create())->delete(route('batches.destroy', $expired->batch))
            ->assertSessionHas('error');
        $this->assertNotNull($expired->batch->fresh());
    }

    public function test_a_fully_written_off_expired_card_says_so_instead_of_linking(): void
    {
        $done = $this->item($this->bottle, 10, batch: ['expiry_date' => today()->subDay()]);
        $this->actingAs($this->office)->post(route('batches.write-offs.expired', $done->batch));
        $this->assertSame(0, $done->fresh()->quantity_remaining);
        $left = $this->item($this->bottle, 6, batch: ['expiry_date' => today()->subDays(2)]);

        $html = $this->actingAs($this->office)->get(route('batches.index'))->assertOk()->getContent();
        $header = fn (BatchItem $i) => substr($html, strpos($html, $i->batch->batch_code), 2500);

        $this->assertStringContainsString('data-written-off-tag', $header($done));
        $this->assertStringNotContainsString(route('batches.show', $done->batch).'#write-offs', $header($done));

        $this->assertStringContainsString(route('batches.show', $left->batch).'#write-offs', $header($left));
        $this->assertStringNotContainsString('data-written-off-tag', $header($left));
    }

    public function test_the_card_pack_bar_does_not_count_written_off_packs_as_sold(): void
    {
        $pack = ProductVariant::create([
            'product_id' => $this->cheese->id, 'name' => 'Vacuum Pack', 'size' => '250g', 'unit' => 'pack',
            'weight_kg' => 0.25, 'base_price' => 4.00, 'is_active' => true, 'is_variable_weight' => true, 'is_bulk_weighed' => true,
        ]);
        $item = $this->item($pack, 20, item: ['quantity_remaining' => 16]); // 4 sold
        $this->writeOff($item, 3);

        $html = $this->actingAs($this->office)->get(route('batches.index'))->assertOk()->getContent();
        $card = substr($html, strpos($html, $item->batch->batch_code));
        $this->assertStringContainsString('· 3 written off', $card);
        $this->assertStringContainsString('· 4 sold', $card);
    }

    public function test_the_weight_box_turns_a_comma_into_a_point(): void
    {
        $item = $this->item($this->wheel, 5);

        $this->actingAs($this->office)->get(route('batches.show', $item->batch))
            ->assertOk()
            ->assertSee("document.execCommand('insertText', false, '.')", false);
    }

    private function expectRefusal(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail("expected a refusal containing \"{$contains}\"");
        } catch (StockException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }
}
