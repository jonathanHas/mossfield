<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History foreign keys go from CASCADE to RESTRICT (cycle 18, OPEN_QUESTIONS #7).
 *
 * The app's deletionBlocker()s (Customer, Product, ProductVariant, Batch)
 * refuse to delete a record that history depends on; the database is the
 * backstop, so a tinker one-liner or a forgotten route can no longer take
 * order lines, batches, allocations or stock logs with it.
 *
 * A child that is part of its parent (a batch's items, an order's lines, a
 * line's allocations, a product's variants) still cascades. Delete rules
 * only — no data changes.
 */
return new class extends Migration
{
    /** table => [column => referenced table] */
    private const KEYS = [
        'orders' => ['customer_id' => 'customers'],
        'batches' => ['product_id' => 'products'],
        'batch_items' => ['product_variant_id' => 'product_variants'],
        'order_items' => ['product_variant_id' => 'product_variants'],
        'order_allocations' => ['batch_item_id' => 'batch_items'],
        'stock_write_offs' => ['batch_item_id' => 'batch_items'],
        'cheese_cutting_logs' => [
            'source_batch_item_id' => 'batch_items',
            'target_batch_item_id' => 'batch_items',
        ],
        'cheese_conversion_logs' => [
            'source_batch_item_id' => 'batch_items',
            'target_batch_item_id' => 'batch_items',
        ],
    ];

    public function up(): void
    {
        $this->rewrite(restrict: true);
    }

    public function down(): void
    {
        $this->rewrite(restrict: false);
    }

    private function rewrite(bool $restrict): void
    {
        foreach (self::KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach (array_keys($columns) as $column) {
                    $blueprint->dropForeign([$column]);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $restrict) {
                foreach ($columns as $column => $references) {
                    $foreign = $blueprint->foreign($column)->references('id')->on($references);
                    $restrict ? $foreign->restrictOnDelete() : $foreign->cascadeOnDelete();
                }
            });
        }
    }
};
