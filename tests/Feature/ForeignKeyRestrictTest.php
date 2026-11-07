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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cycle 18 (OPEN_QUESTIONS #7): the database refuses what the app refuses.
 * The history foreign keys are RESTRICT, so a raw delete of a parent with
 * history fails; a child that is part of its parent still cascades.
 */
class ForeignKeyRestrictTest extends TestCase
{
    use RefreshDatabase;

    private const RESTRICT = [
        ['orders', 'customer_id'],
        ['batches', 'product_id'],
        ['batch_items', 'product_variant_id'],
        ['order_items', 'product_variant_id'],
        ['order_allocations', 'batch_item_id'],
        ['stock_write_offs', 'batch_item_id'],
        ['cheese_cutting_logs', 'source_batch_item_id'],
        ['cheese_cutting_logs', 'target_batch_item_id'],
        ['cheese_conversion_logs', 'source_batch_item_id'],
        ['cheese_conversion_logs', 'target_batch_item_id'],
    ];

    private const CASCADE = [
        ['batch_items', 'batch_id'],
        ['order_items', 'order_id'],
        ['order_allocations', 'order_item_id'],
        ['product_variants', 'product_id'],
    ];

    public function test_history_keys_restrict_and_part_of_parent_keys_cascade(): void
    {
        $rules = collect(DB::select(
            'SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, r.DELETE_RULE AS d
               FROM information_schema.REFERENTIAL_CONSTRAINTS r
               JOIN information_schema.KEY_COLUMN_USAGE k
                 ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
                AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
                AND k.TABLE_NAME = r.TABLE_NAME
              WHERE r.CONSTRAINT_SCHEMA = ?',
            [DB::getDatabaseName()]
        ))->mapWithKeys(fn ($row) => ["{$row->t}.{$row->c}" => $row->d]);

        foreach (self::RESTRICT as [$table, $column]) {
            $this->assertSame('RESTRICT', $rules["{$table}.{$column}"] ?? null, "{$table}.{$column}");
        }
        foreach (self::CASCADE as [$table, $column]) {
            $this->assertSame('CASCADE', $rules["{$table}.{$column}"] ?? null, "{$table}.{$column}");
        }
    }

    public function test_a_customer_with_an_order_cannot_be_deleted_raw(): void
    {
        $line = $this->orderLine($this->variant());
        $customerId = $line->order->customer_id;

        $this->assertRefused(fn () => DB::table('customers')->where('id', $customerId)->delete());
        $this->assertDatabaseHas('orders', ['id' => $line->order_id]);
    }

    public function test_a_product_with_a_batch_cannot_be_deleted_raw(): void
    {
        $item = $this->batchItem($this->variant());

        $this->assertRefused(fn () => DB::table('products')->where('id', $item->batch->product_id)->delete());
        $this->assertDatabaseHas('batches', ['id' => $item->batch_id]);
    }

    public function test_a_variant_with_an_order_line_cannot_be_deleted_raw(): void
    {
        $variant = $this->variant();
        $line = $this->orderLine($variant);

        $this->assertRefused(fn () => DB::table('product_variants')->where('id', $variant->id)->delete());
        $this->assertDatabaseHas('order_items', ['id' => $line->id]);
    }

    public function test_a_batch_item_with_an_allocation_cannot_be_deleted_raw(): void
    {
        $variant = $this->variant();
        $item = $this->batchItem($variant);
        $allocation = OrderAllocation::create([
            'order_item_id' => $this->orderLine($variant)->id, 'batch_item_id' => $item->id,
            'quantity_allocated' => 2, 'quantity_fulfilled' => 0, 'allocated_at' => now(),
        ]);

        $this->assertRefused(fn () => DB::table('batch_items')->where('id', $item->id)->delete());
        $this->assertRefused(fn () => DB::table('batches')->where('id', $item->batch_id)->delete());
        $this->assertDatabaseHas('order_allocations', ['id' => $allocation->id]);
    }

    public function test_a_batch_without_history_still_takes_its_items(): void
    {
        $item = $this->batchItem($this->variant());

        DB::table('batches')->where('id', $item->batch_id)->delete();

        $this->assertDatabaseMissing('batches', ['id' => $item->batch_id]);
        $this->assertDatabaseMissing('batch_items', ['id' => $item->id]);
    }

    private function assertRefused(callable $delete): void
    {
        try {
            $delete();
            $this->fail('The delete went through; the foreign key should have refused it.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    private function variant(): ProductVariant
    {
        $product = Product::create(['name' => 'Mossfield FK Milk', 'type' => 'milk', 'is_active' => true]);

        return ProductVariant::create([
            'product_id' => $product->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.00, 'is_active' => true,
        ]);
    }

    private function batchItem(ProductVariant $variant): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $variant->product_id, 'production_date' => today()->subDays(2),
            'expiry_date' => today()->addDays(8), 'raw_milk_litres' => 100, 'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => 10, 'quantity_remaining' => 10, 'unit_weight_kg' => 1.0,
        ]);
    }

    private function orderLine(ProductVariant $variant): OrderItem
    {
        $customer = Customer::create([
            'name' => 'FK Shop', 'email' => 'fk@example.ie', 'address' => '1 Main St',
            'country' => 'Ireland', 'payment_terms' => 'net_30', 'is_active' => true,
        ]);
        $order = Order::create([
            'customer_id' => $customer->id, 'order_date' => today(), 'delivery_date' => today(),
            'status' => 'pending', 'payment_status' => 'pending',
        ]);

        return OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $variant->id,
            'quantity_ordered' => 2, 'unit_price' => 2.00,
        ]);
    }
}
