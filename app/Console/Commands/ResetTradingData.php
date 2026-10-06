<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\BatchItem;
use Database\Seeders\BatchSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The standing reset between test passes on a dev database.
 *
 * Wipes the trading history (stock, orders, allocations, cutting/conversion
 * logs, invoicing, payments, statements, the email log), restarts document
 * numbering, and regenerates stock with BatchSeeder — while keeping the
 * reference data that isn't reproducible from any seeder: users, the real
 * customer set (+ special prices), products, variants, delivery runs.
 *
 * Why not the seeders: db:seed is additive (BatchSeeder adds 3–5 batches per
 * product per run, CustomerSeeder has no duplicate guard) and migrate:fresh
 * destroys the customer and user rows.
 *
 * Dry run by default; --confirm executes; refuses in production outright.
 * --seed=N makes BatchSeeder deterministic (it draws everything from
 * rand()/array_rand(), which share the MT generator), so a figure written down
 * during one pass still matches the database after the next reset — on the same
 * calendar day; production dates are relative to today, so batch codes shift
 * with the date while counts, quantities and day-offsets stay fixed.
 */
class ResetTradingData extends Command
{
    protected $signature = 'mossfield:reset-trading-data
                            {--confirm : Actually execute (default is a dry run that changes nothing)}
                            {--seed= : Seed the random generator so BatchSeeder regenerates the same stock every time}';

    protected $description = 'Wipe trading history and regenerate stock, keeping users, customers, products, variants and delivery runs';

    /**
     * Trading tables, in delete order. Derived from the foreign keys in the
     * schema (see ResetTradingDataTest::test_every_table_in_the_schema_is_classified,
     * which fails if a migration adds a table nobody has classified):
     *
     *  - payment_allocations and credit_notes RESTRICT on invoices, so they go
     *    before invoices; credit_note_lines before credit_notes.
     *  - order_allocations and both log tables CASCADE from batch_items, but
     *    are deleted explicitly and first — nothing here relies on a cascade.
     *  - orders.invoice_id / invoice_lines.order_id are SET NULL either way.
     *  - batch_items before batches (CASCADE, again not relied on).
     */
    public const WIPE_ORDER = [
        'document_emails',
        'statements',
        'payment_allocations',
        'payments',
        'credit_note_lines',
        'credit_notes',
        'invoice_lines',
        'invoices',
        'order_allocations',
        'cheese_cutting_logs',
        'cheese_conversion_logs',
        'stock_write_offs',
        'order_items',
        'orders',
        'batch_items',
        'batches',
    ];

    /** Reference data that survives a reset. */
    public const KEEP = [
        'users',
        'customers',
        'customer_special_prices',
        'products',
        'product_variants',
        'delivery_runs',
        'write_off_reasons',
        'settings', // admin-set values (cycle 10) — configuration, not trading
    ];

    /** Framework/infra tables — neither trading data nor reference data. */
    public const INFRASTRUCTURE = [
        'migrations',
        'document_sequences', // reset to 1, not wiped
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('mossfield:reset-trading-data refuses to run in production.');

            return self::FAILURE;
        }

        $counts = collect(self::WIPE_ORDER)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
        $sequences = DB::table('document_sequences')->get();
        $products = BatchSeeder::products()->pluck('name');

        $this->line('Trading tables to wipe:');
        $this->table(['table', 'rows'], $counts->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line('document_sequences to reset to 1: '.($sequences->isEmpty() ? '(none)' : $sequences->map(fn ($s) => "{$s->key} (next {$s->next_value})")->implode(', ')));
        $this->line('Kept untouched: '.implode(', ', self::KEEP));
        $this->line('Then BatchSeeder against '.$products->count().' products ('.$products->implode(', ').')'
            .($this->option('seed') !== null ? ' with --seed='.$this->option('seed') : ' (unseeded — random)').'.');

        if ($pending = DB::table('jobs')->count()) {
            $this->warn("$pending queued job(s) in `jobs` are left in place — any that reference wiped rows will fail when run.");
        }

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->warn('Dry run — nothing changed.');
            $this->warn('Re-run with --confirm to execute.');

            return self::FAILURE;
        }

        $deleted = DB::transaction(function () {
            $deleted = [];
            foreach (self::WIPE_ORDER as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            // Numbering restarts with the history it numbered. (Rows are kept,
            // not deleted: a missing row is recreated at 1 by DocumentSequence::next()
            // anyway, but keeping them makes the reset visible in the summary.)
            $deleted['document_sequences (reset)'] = DB::table('document_sequences')
                ->update(['next_value' => 1, 'updated_at' => now()]);

            return $deleted;
        });

        // Restart ids too, so "/batches/17" in a write-up means the same batch
        // after the next reset. DDL commits implicitly on MySQL, so this must
        // stay outside the transaction above — and is skipped when we are
        // already inside one (the test suite's RefreshDatabase wrapper).
        if (DB::transactionLevel() === 0) {
            foreach (self::WIPE_ORDER as $table) {
                DB::statement("ALTER TABLE `$table` AUTO_INCREMENT = 1");
            }
        }

        if (($seed = $this->option('seed')) !== null) {
            mt_srand((int) $seed);
        }

        (new BatchSeeder)->setContainer($this->laravel)->setCommand($this)->__invoke();

        $this->newLine();
        $this->info('Deleted:');
        $this->table(['table', 'rows'], collect($deleted)->map(fn ($n, $t) => [$t, $n])->values()->all());

        $byType = Batch::query()
            ->join('products', 'products.id', '=', 'batches.product_id')
            ->selectRaw('products.type, count(*) as batches')
            ->groupBy('products.type')
            ->pluck('batches', 'type');
        $this->info(sprintf(
            'Regenerated %d batches / %d batch items (%s), %d units on the shelf%s.',
            Batch::count(),
            BatchItem::count(),
            $byType->map(fn ($n, $type) => "$type $n")->implode(', '),
            (int) BatchItem::sum('quantity_remaining'),
            $seed !== null ? ", --seed=$seed" : '',
        ));

        return self::SUCCESS;
    }
}
