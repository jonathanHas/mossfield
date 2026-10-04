<?php

namespace Tests\Feature;

use App\Console\Commands\ResetTradingData;
use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\DeliveryRun;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `mossfield:reset-trading-data` — the standing reset between test passes.
 * Wipes trading history, keeps the hand-assembled reference data, regenerates
 * stock; deterministic under --seed so figures written down during a pass
 * still match the database after the next reset.
 */
class ResetTradingDataTest extends TestCase
{
    use RefreshDatabase;

    private Customer $shop;

    private ProductVariant $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductSeeder::class);
        User::factory()->admin()->create(['username' => 'keep_me']);

        $run = DeliveryRun::create(['name' => 'Tuesday town', 'day_of_week' => 2, 'is_active' => true]);
        $this->shop = Customer::create([
            'name' => 'Avoca Test',
            'email' => 'avoca@example.ie',
            'address' => '1 Main St', 'city' => 'Birr', 'postal_code' => 'R42', 'country' => 'Ireland',
            'payment_terms' => 'net_30',
            'is_active' => true,
            'delivery_run_id' => $run->id,
            'run_position' => 1,
        ]);
        $this->bottle = ProductVariant::where('name', '1L Bottle')->firstOrFail();
        CustomerSpecialPrice::create(['customer_id' => $this->shop->id, 'product_variant_id' => $this->bottle->id, 'price' => 1.75]);
    }

    /** A full trading trail: stock → order → pick → dispatch → invoice, plus a cut log. */
    private function trade(): void
    {
        $batch = Batch::create([
            'product_id' => $this->bottle->product_id,
            'production_date' => today()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        $item = BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->bottle->id,
            'quantity_produced' => 20, 'quantity_remaining' => 20, 'unit_weight_kg' => 1,
        ]);

        $order = Order::create([
            'customer_id' => $this->shop->id,
            'order_date' => today()->toDateString(),
            'delivery_date' => today()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $this->bottle->id,
            'quantity_ordered' => 5, 'unit_price' => 1.75,
        ]);
        $alloc = $line->allocateFromBatchItem($item, 5);
        $line->fulfillAllocation($alloc, 5);
        $order->update(['status' => 'dispatched', 'dispatched_at' => now()]);
        $order->calculateTotals();

        app(InvoiceService::class)->issueForOrder($order->fresh(), User::first());
    }

    private function trailCounts(): array
    {
        return collect(ResetTradingData::WIPE_ORDER)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    // ---------------------------------------------------------------- cases

    public function test_it_refuses_without_confirm_and_changes_nothing(): void
    {
        $this->trade();
        $before = $this->trailCounts();
        $this->assertGreaterThan(0, $before['invoices']);

        $this->artisan('mossfield:reset-trading-data')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('--confirm')
            ->assertExitCode(1);

        $this->assertSame($before, $this->trailCounts(), 'a dry run must not delete anything');
        $this->assertSame(2, DocumentSequence::query()->max('next_value'), 'nor touch the numbering');
    }

    public function test_it_refuses_to_run_in_production_even_with_confirm(): void
    {
        $this->trade();
        $before = $this->trailCounts();

        $this->app['env'] = 'production';

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true])
            ->expectsOutputToContain('production')
            ->assertExitCode(1);

        $this->assertSame($before, $this->trailCounts());
    }

    public function test_reference_data_survives_and_trading_tables_are_empty(): void
    {
        $this->trade();

        $keep = [
            'users' => User::count(),
            'customers' => Customer::count(),
            'customer_special_prices' => CustomerSpecialPrice::count(),
            'products' => Product::count(),
            'product_variants' => ProductVariant::count(),
            'delivery_runs' => DeliveryRun::count(),
        ];
        foreach ($keep as $table => $n) {
            $this->assertGreaterThan(0, $n, "$table fixture present before reset");
        }

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 1])
            ->assertExitCode(0);

        foreach ($keep as $table => $n) {
            $this->assertSame($n, DB::table($table)->count(), "$table must survive untouched");
        }
        $this->assertSame('keep_me', User::first()->username);
        $this->assertSame($this->shop->id, Customer::first()->id);
        $this->assertSame('1.75', (string) CustomerSpecialPrice::first()->price);
        $this->assertNotNull(Customer::first()->delivery_run_id, 'the customer is still on their run');

        foreach (ResetTradingData::WIPE_ORDER as $table) {
            if (in_array($table, ['batches', 'batch_items'], true)) {
                continue; // regenerated — checked separately
            }
            $this->assertSame(0, DB::table($table)->count(), "$table must be empty");
        }
    }

    public function test_document_sequences_restart_from_one(): void
    {
        $this->trade();
        $this->assertSame(2, (int) DocumentSequence::where('key', 'invoice')->value('next_value'), 'one invoice was issued');

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 1])->assertExitCode(0);

        $this->assertSame(1, (int) DocumentSequence::where('key', 'invoice')->value('next_value'));
        $this->assertSame(0, Invoice::count());

        // And the next invoice really is INV-000001 again.
        $this->trade();
        $this->assertSame('INV-000001', Invoice::first()->number);
    }

    public function test_stock_exists_again_afterwards(): void
    {
        $this->trade();

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 7])
            ->expectsOutputToContain('Regenerated')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Batch::count());
        $this->assertGreaterThan(0, BatchItem::count());
        foreach (['milk', 'yoghurt', 'cheese'] as $type) {
            $this->assertTrue(Batch::whereHas('product', fn ($q) => $q->where('type', $type))->exists(), "$type stock regenerated");
        }
        $this->assertGreaterThan(0, BatchItem::sum('quantity_remaining'), 'some of it is on the shelf');

        // Mature is reached by conversion, never made fresh — the seeder must not
        // invent Mature batches from raw milk.
        $mature = Product::where('name', 'Mossfield Mature Cheese')->firstOrFail();
        $this->assertSame(0, Batch::where('product_id', $mature->id)->count());
    }

    public function test_the_same_seed_produces_identical_batches(): void
    {
        $snapshot = function (): array {
            return Batch::orderBy('id')->with('batchItems')->get()->map(fn ($b) => [
                'product_id' => $b->product_id,
                'batch_code' => $b->batch_code,
                'production_date' => $b->production_date->toDateString(),
                'expiry_date' => $b->expiry_date?->toDateString(),
                'raw_milk_litres' => (string) $b->raw_milk_litres,
                'wheels_produced' => $b->wheels_produced,
                'notes' => $b->notes,
                'status' => $b->status,
                'items' => $b->batchItems->sortBy('product_variant_id')->values()->map(fn ($i) => [
                    $i->product_variant_id, $i->quantity_produced, $i->quantity_remaining,
                ])->all(),
            ])->all();
        };

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 42])->assertExitCode(0);
        $first = $snapshot();

        $this->trade(); // dirty it in between, as a test pass would

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 42])->assertExitCode(0);
        $second = $snapshot();

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second, 'the same --seed must reproduce the same stock');

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 43])->assertExitCode(0);
        $this->assertNotSame($first, $snapshot(), 'a different seed produces different stock');
    }

    public function test_it_prints_a_per_table_summary(): void
    {
        $this->trade();

        $this->artisan('mossfield:reset-trading-data', ['--confirm' => true, '--seed' => 1])
            ->expectsOutputToContain('invoices')
            ->expectsOutputToContain('order_allocations')
            ->expectsOutputToContain('document_sequences')
            ->expectsOutputToContain('Regenerated')
            ->assertExitCode(0);
    }

    /**
     * Every table in the schema must be classified — kept, wiped, or
     * infrastructure. A new trading table added by a migration fails here until
     * someone decides which it is, so the reset can't silently leave residue.
     */
    public function test_every_table_in_the_schema_is_classified(): void
    {
        $all = collect(Schema::getTableListing())->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t);
        $classified = collect(ResetTradingData::WIPE_ORDER)
            ->merge(ResetTradingData::KEEP)
            ->merge(ResetTradingData::INFRASTRUCTURE);

        $this->assertSame([], $all->diff($classified)->values()->all(), 'unclassified tables — decide whether the reset wipes or keeps them');
        $this->assertSame([], $classified->diff($all)->values()->all(), 'classified tables that no longer exist');
    }
}
