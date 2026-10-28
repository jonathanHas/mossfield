<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 2026-09-13 consolidation pass: finding 7 (indistinguishable variant
 * options), finding 8 (the batch page and cards fold cut/converted wheels into
 * "sold"), and the docs/planImp/SMALL_FIXES.md items that earn a test (the `/` route, the
 * cutting empty state).
 */
class ConsolidationPassTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private User $factory;

    private Customer $shop;

    private array $cheeses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();
        $this->factory = User::factory()->factoryWorker()->create();
        $this->shop = Customer::create([
            'name' => 'Consolidation Shop', 'email' => 'cons@example.ie',
            'address' => '1 Main St', 'city' => 'Birr', 'postal_code' => 'R42', 'country' => 'Ireland',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);

        // Three cheeses, each with a "Whole Wheel" and a "Vacuum Pack" — the
        // collision the finding describes. Mature must exist for release().
        foreach (['Mossfield Farmhouse Cheese', 'Mossfield Mediterranean Cheese', 'Mossfield Mature Cheese'] as $name) {
            $product = Product::create(['name' => $name, 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
            $this->cheeses[$name] = [
                'product' => $product,
                'wheel' => ProductVariant::create([
                    'product_id' => $product->id, 'name' => 'Whole Wheel', 'size' => 'wheel', 'unit' => 'wheel',
                    'weight_kg' => 5.000, 'base_price' => 16.00, 'is_variable_weight' => true, 'is_priced_by_weight' => true, 'is_active' => true,
                ]),
                'pack' => ProductVariant::create([
                    'product_id' => $product->id, 'name' => 'Vacuum Pack', 'size' => 'pack', 'unit' => 'pack',
                    'weight_kg' => 0.250, 'base_price' => 4.50, 'is_variable_weight' => true, 'is_bulk_weighed' => true, 'is_active' => true,
                ]),
            ];
        }
    }

    private function farmhouseWheels(int $wheels = 10, int $held = 0): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->cheeses['Mossfield Farmhouse Cheese']['product']->id,
            'production_date' => now()->subMonths(6)->toDateString(),
            'raw_milk_litres' => 1000, 'wheels_produced' => $wheels, 'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->cheeses['Mossfield Farmhouse Cheese']['wheel']->id,
            'quantity_produced' => $wheels, 'quantity_remaining' => $wheels, 'quantity_maturing' => $held, 'unit_weight_kg' => 5.000,
        ]);
    }

    /** 10 wheels: 2 sold, 1 cut, 1 converted, 6 on hand (1 of them held). */
    private function workedBatch(): BatchItem
    {
        $wheelItem = $this->farmhouseWheels(10);
        $wheel = $this->cheeses['Mossfield Farmhouse Cheese']['wheel'];

        $order = Order::create([
            'customer_id' => $this->shop->id, 'order_date' => now()->toDateString(), 'delivery_date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_status' => 'pending',
        ]);
        $item = OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $wheel->id, 'quantity_ordered' => 2, 'unit_price' => 16]);
        $this->actingAs($this->factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $wheelItem->id, 'quantity' => 2, 'weights' => [4.8, 5.2],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->office)->post(route('cheese-cutting.store', $wheelItem), [
            'cut_date' => today()->toDateString(), 'vacuum_pack_variant_id' => $this->cheeses['Mossfield Farmhouse Cheese']['pack']->id,
            'wheel_weight_kg' => 5.0, 'vacuum_packs_created' => 18, 'total_weight_kg' => 4.68,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->office)->post(route('cheese-conversion.hold', $wheelItem), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->actingAs($this->office)->post(route('cheese-conversion.release', $wheelItem), ['quantity' => 1])->assertSessionHasNoErrors();

        return $wheelItem->fresh();
    }

    /** All <option> texts, grouped per <select>, from a page. */
    private function optionTextsPerSelect(string $html): array
    {
        preg_match_all('/<select\b.*?<\/select>/s', $html, $selects);
        $out = [];
        foreach ($selects[0] as $select) {
            preg_match_all('/<option[^>]*value="(\d+)"[^>]*>(.*?)<\/option>/s', $select, $m);
            $texts = array_map(fn ($t) => trim(preg_replace('/\s+/', ' ', strip_tags($t))), $m[2]);
            if (count($texts) > 1) {
                $out[] = $texts;
            }
        }

        return $out;
    }

    private function assertNoDuplicateOptions(string $html, string $page): void
    {
        $selects = $this->optionTextsPerSelect($html);
        $this->assertNotEmpty($selects, "$page has a product select");
        foreach ($selects as $texts) {
            $dupes = array_filter(array_count_values($texts), fn ($n) => $n > 1);
            $this->assertSame([], $dupes, "$page: options must not read identically — ".json_encode(array_keys($dupes)));
        }
    }

    // ------------------------------------------------------------ finding 7

    public function test_no_two_product_options_read_identically_on_any_order_form(): void
    {
        $order = Order::create([
            'customer_id' => $this->shop->id, 'order_date' => now()->toDateString(),
            'status' => 'pending', 'payment_status' => 'pending',
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $this->cheeses['Mossfield Farmhouse Cheese']['wheel']->id, 'quantity_ordered' => 1, 'unit_price' => 16]);

        $create = $this->actingAs($this->office)->get(route('orders.create'))->assertOk()->getContent();
        $this->assertNoDuplicateOptions($create, '/orders/create');
        $this->assertStringContainsString('Farmhouse Cheese · Whole Wheel (€16.00/kg)', $create);
        $this->assertStringContainsString('Mature Cheese · Whole Wheel (€16.00/kg)', $create);

        $show = $this->actingAs($this->office)->get(route('orders.show', $order))->assertOk()->getContent();
        $this->assertNoDuplicateOptions($show, '/orders/{order} add item');

        $customer = $this->actingAs($this->office)->get(route('customers.show', $this->shop))->assertOk()->getContent();
        $this->assertNoDuplicateOptions($customer, '/customers/{customer} special prices');

        // The chilled-run sheet's "Add line…" select in edit mode.
        $run = DeliveryRun::create(['name' => 'Tuesday', 'day_of_week' => 2, 'is_active' => true]);
        $this->shop->update(['delivery_run_id' => $run->id, 'run_position' => 1]);
        $sheet = $this->actingAs($this->office)->get(route('chilled-runs.index', ['run' => $run->id, 'edit' => $this->shop->id]))->assertOk()->getContent();
        $this->assertNoDuplicateOptions($sheet, '/chilled-runs add line');
    }

    public function test_the_javascript_row_template_on_the_order_form_is_disambiguated_too(): void
    {
        $html = $this->actingAs($this->office)->get(route('orders.create'))->assertOk()->getContent();

        // The addItem() template is a JS string; every Whole Wheel in it must carry its product.
        preg_match('/div\.innerHTML = `(.*?)`;/s', $html, $m);
        $this->assertNotEmpty($m, 'the add-row template is present');
        $this->assertStringNotContainsString('>\s*Whole Wheel (', $m[1]);
        $this->assertSame(3, substr_count($m[1], '· Whole Wheel ('), 'all three wheels are prefixed in the template');
    }

    // ------------------------------------------------------------ finding 8

    public function test_the_batch_page_accounts_for_every_wheel_by_state_and_agrees_with_stock(): void
    {
        $wheelItem = $this->workedBatch();
        $batch = $wheelItem->batch;

        $html = $this->actingAs($this->office)->get(route('batches.show', $batch))->assertOk()->getContent();

        // Table header carries the states, and the Whole Wheel row reads 10 · 6 · 2 · 1 · 1.
        $this->assertStringContainsString('>Converted<', $html);
        $this->assertStringContainsString('>Cut<', $html);
        preg_match('/<tr[^>]*>\s*<td[^>]*>Whole Wheel<\/td>.*?<\/tr>/s', $html, $row);
        $this->assertNotEmpty($row, 'Whole Wheel row present');
        preg_match_all('/<td[^>]*>\s*(\d+)\s*<\/td>/', $row[0], $cells);
        $this->assertSame(['10', '6', '2', '1', '1'], $cells[1], 'produced · on hand · sold · cut · converted');

        $stock = collect(app(StockOverviewService::class)->build()['cheese']['wheels'])
            ->first(fn ($r) => $r['batch_code'] === $batch->batch_code)['segments'];
        $this->assertSame(6, $stock['available'] + $stock['maturing']);
        $this->assertSame(2, $stock['sold']);
        $this->assertSame(1, $stock['cut']);
        $this->assertSame(1, $stock['converted']);
    }

    public function test_the_batch_cards_no_longer_fold_converted_wheels_into_sold(): void
    {
        $wheelItem = $this->workedBatch();
        $code = $wheelItem->batch->batch_code;
        // The release flash names the mature batch (cycle 14), whose code
        // starts with this one: drop it so strpos() finds the card.
        $this->flushSession();

        foreach (['batches.index' => ['type' => 'cheese'], 'cheese-cutting.index' => []] as $route => $params) {
            $html = $this->actingAs($this->office)->get(route($route, $params))->assertOk()->getContent();
            $start = strpos($html, $code);
            $this->assertNotFalse($start, "$route lists the batch");
            $card = substr($html, $start, 6000);

            $this->assertStringContainsString('2 sold', $card, "$route: 2 wheels were sold, not 3");
            $this->assertStringContainsString('1 converted', $card, "$route: the converted wheel is its own state");
            $this->assertStringContainsString('1 cut', $card);
            $this->assertStringContainsString('1 maturing', $card);
            $this->assertStringContainsString('5 free', $card);
            $this->assertStringNotContainsString('3 sold', $card);
        }
    }

    public function test_the_batch_cards_items_table_splits_sold_from_cut_and_converted(): void
    {
        $wheelItem = $this->workedBatch();
        // The raw figure lumps 2 sold + 1 cut + 1 converted together.
        $this->assertSame(4, $wheelItem->quantity_sold);
        $this->flushSession(); // the release flash names the mature batch (cycle 14)

        $html = $this->actingAs($this->office)->get(route('batches.index', ['type' => 'cheese']))->assertOk()->getContent();
        $card = substr($html, strpos($html, $wheelItem->batch->batch_code), 20000);
        preg_match_all('/data-sold-cell>(.*?)<\/td>/s', $card, $m);
        $cells = array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))), $m[1]);

        $this->assertContains('2 · 1 cut · 1 converted', $cells, 'the wheel row reads wheelStates()');
        $this->assertNotContains('4', $cells);
        $this->assertContains('0', $cells, 'the pack row keeps quantity_sold');
    }

    // ------------------------------------------------- small fixes with tests

    public function test_the_root_route_no_longer_serves_the_welcome_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($this->office)->get('/')->assertRedirect(route('dashboard'));
        $this->assertStringNotContainsString('Laravel', $this->actingAs($this->office)->get('/')->getContent());
    }

    public function test_the_cutting_empty_state_says_wheels_are_spoken_for_when_they_are(): void
    {
        // Ready, but every wheel is held.
        $this->farmhouseWheels(5, held: 5);

        $this->actingAs($this->office)->get(route('cheese-cutting.index'))->assertOk()
            ->assertSee('spoken for')
            ->assertDontSee('need to mature');
    }

    public function test_the_cutting_empty_state_still_says_nothing_is_ready_when_nothing_is(): void
    {
        $batch = Batch::create([
            'product_id' => $this->cheeses['Mossfield Farmhouse Cheese']['product']->id,
            'production_date' => today()->toDateString(), 'raw_milk_litres' => 100, 'wheels_produced' => 5, 'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->cheeses['Mossfield Farmhouse Cheese']['wheel']->id,
            'quantity_produced' => 5, 'quantity_remaining' => 5, 'unit_weight_kg' => 5.000,
        ]);

        $this->actingAs($this->office)->get(route('cheese-cutting.index'))->assertOk()
            ->assertSee('need to mature')
            ->assertDontSee('spoken for');
    }

    public function test_the_cut_form_does_not_offer_the_action_when_no_wheel_is_free(): void
    {
        $held = $this->farmhouseWheels(5, held: 5);

        $html = $this->actingAs($this->office)->get(route('cheese-cutting.create', $held))->assertOk()->getContent();
        $this->assertStringContainsString('No free wheels', $html);
        $this->assertStringNotContainsString('<form method="POST" action="'.route('cheese-cutting.store', $held), $html);
    }
}
