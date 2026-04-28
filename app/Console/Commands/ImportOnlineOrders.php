<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OnlineOrderImportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Import online orders from the Mossorders portal into the office orders table.
 *
 * This command creates local Order and OrderItem records from Mossorders data,
 * linking them to customers via mossorders_user_id. It is idempotent and safe
 * to run multiple times.
 */
class ImportOnlineOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mossfield:import-online-orders {--since= : Only import orders updated since this ISO8601 datetime}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import online orders from Mossorders into the office orders table';

    /**
     * Import statistics.
     */
    private int $processedCount = 0;

    private int $importedCount = 0;

    private int $skippedExisting = 0;

    private int $skippedUnmappedCustomer = 0;

    private int $skippedInvalidData = 0;

    /**
     * Execute the console command.
     */
    public function handle(OnlineOrderImportService $importService): int
    {
        $correlationId = (string) Str::uuid();
        $startNs = hrtime(true);

        $this->info('Starting Mossorders online order import...');
        $this->newLine();

        // Get the optional 'since' parameter
        $since = $this->option('since');

        if ($since) {
            $this->info("Filtering orders updated since: {$since}");
            $this->newLine();
        }

        // Fetch orders from Mossorders API
        $orders = $importService->fetchOrders($since);

        // Handle empty result
        if (empty($orders)) {
            $this->warn('No online orders returned from Mossorders.');
            $this->info('Nothing to import.');

            $this->logRunSummary('success', $correlationId, $startNs, $since, fetched: 0);
            Cache::forever('sync:import_orders:last_ok', now()->toIso8601String());

            return Command::SUCCESS;
        }

        // Process each order
        $this->info('Processing '.count($orders).' order(s) from Mossorders...');
        $this->newLine();

        foreach ($orders as $orderPayload) {
            $this->processedCount++;

            try {
                $this->processOrder($orderPayload);
            } catch (\Exception $e) {
                $this->error("Error processing order: {$e->getMessage()}");
                Log::channel('sync')->error('import_orders: order exception', [
                    'correlation_id' => $correlationId,
                    'payload' => $orderPayload,
                    'error' => $e->getMessage(),
                ]);
                $this->skippedInvalidData++;
            }
        }

        // Display summary
        $this->displaySummary();

        $this->logRunSummary('success', $correlationId, $startNs, $since, fetched: count($orders));
        Cache::forever('sync:import_orders:last_ok', now()->toIso8601String());

        return Command::SUCCESS;
    }

    private function logRunSummary(string $outcome, string $correlationId, int $startNs, ?string $since, int $fetched): void
    {
        Log::channel('sync')->info('import_orders: run summary', [
            'correlation_id' => $correlationId,
            'outcome' => $outcome,
            'duration_ms' => (int) round((hrtime(true) - $startNs) / 1_000_000),
            'since' => $since,
            'fetched' => $fetched,
            'processed' => $this->processedCount,
            'imported' => $this->importedCount,
            'skipped_existing' => $this->skippedExisting,
            'skipped_unmapped_customer' => $this->skippedUnmappedCustomer,
            'skipped_invalid_data' => $this->skippedInvalidData,
        ]);
    }

    /**
     * Process a single order payload.
     */
    private function processOrder(array $payload): void
    {
        // Validate required fields
        if (! isset($payload['mossorders_order_id'])) {
            $this->warn('Skipping order: missing mossorders_order_id');
            $this->skippedInvalidData++;

            return;
        }

        $mossordersOrderId = $payload['mossorders_order_id'];
        $orderNumber = $payload['order_number'] ?? "MSF-{$mossordersOrderId}";

        // Check if order already exists (idempotency)
        if (Order::where('mossorders_order_id', $mossordersOrderId)->exists()) {
            $this->line("  ⏭️  Skipping {$orderNumber}: already imported");
            $this->skippedExisting++;

            return;
        }

        // Resolve customer
        $mossordersUserId = $payload['customer']['mossorders_user_id'] ?? null;

        if (! $mossordersUserId) {
            $this->warn("  ⚠️  Skipping {$orderNumber}: no mossorders_user_id in customer data");
            $this->skippedUnmappedCustomer++;

            return;
        }

        $customer = Customer::where('mossorders_user_id', $mossordersUserId)->first();

        if (! $customer) {
            $customerEmail = $payload['customer']['email'] ?? 'unknown';
            $customerName = $payload['customer']['name'] ?? 'unknown';

            $this->warn("  ⚠️  Skipping {$orderNumber}: customer not mapped");
            $this->line("     Mossorders User ID: {$mossordersUserId}");
            $this->line("     Email: {$customerEmail}");
            $this->line("     Name: {$customerName}");

            Log::warning('Order skipped due to unmapped customer', [
                'order_number' => $orderNumber,
                'mossorders_order_id' => $mossordersOrderId,
                'mossorders_user_id' => $mossordersUserId,
                'customer_email' => $customerEmail,
                'customer_name' => $customerName,
            ]);

            $this->skippedUnmappedCustomer++;

            return;
        }

        // Validate items
        if (empty($payload['items'])) {
            $this->warn("  ⚠️  Skipping {$orderNumber}: no items in order");
            $this->skippedInvalidData++;

            return;
        }

        // Import the order within a transaction
        DB::transaction(function () use ($payload, $customer, $mossordersOrderId, $orderNumber) {
            // Parse order date
            $orderDate = isset($payload['placed_at'])
                ? Carbon::parse($payload['placed_at'])
                : now();

            // Create the order
            $order = Order::create([
                'mossorders_order_id' => $mossordersOrderId,
                'customer_id' => $customer->id,
                'order_date' => $orderDate,
                'delivery_date' => null, // Will be set later if needed
                'status' => 'pending', // All imported orders start as pending
                'payment_status' => 'pending',
                'subtotal' => $payload['totals']['subtotal'] ?? 0,
                'tax_amount' => $payload['totals']['tax'] ?? 0,
                'total_amount' => $payload['totals']['grand_total'] ?? 0,
                'delivery_address' => null, // Not included in this version
                'notes' => $payload['notes'] ?? "Imported from Mossorders order {$orderNumber}",
                // order_number will be auto-generated by the Order model
            ]);

            // Import order items
            $itemsImported = 0;
            $itemsSkipped = 0;

            foreach ($payload['items'] as $itemPayload) {
                $officeVariantId = $itemPayload['office_variant_id'] ?? null;

                if (! $officeVariantId) {
                    $productName = $itemPayload['product_name'] ?? 'unknown';
                    $this->line("     ⚠️  Skipping item: {$productName} (no office_variant_id)");
                    Log::warning('Order item skipped: missing office_variant_id', [
                        'order_number' => $orderNumber,
                        'item' => $itemPayload,
                    ]);
                    $itemsSkipped++;

                    continue;
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $officeVariantId,
                    'quantity_ordered' => $itemPayload['quantity'] ?? 0,
                    'quantity_allocated' => 0,
                    'quantity_fulfilled' => 0,
                    'unit_price' => $itemPayload['unit_price'] ?? 0,
                    // line_total will be auto-calculated by OrderItem model
                    'notes' => $itemPayload['product_name'] ?? null,
                ]);

                $itemsImported++;
            }

            // Verify at least one item was imported
            if ($itemsImported === 0) {
                throw new \Exception("No valid items to import for order {$orderNumber}");
            }

            $this->line("  ✅ Imported {$order->order_number} (was {$orderNumber})");
            $this->line("     Customer: {$customer->name}");
            $this->line("     Items: {$itemsImported} imported".($itemsSkipped > 0 ? ", {$itemsSkipped} skipped" : ''));
            $this->line("     Total: €".number_format($order->total_amount, 2));

            $this->importedCount++;
        });
    }

    /**
     * Display import summary.
     */
    private function displaySummary(): void
    {
        $this->newLine();
        $this->line('═════════════════════════════════════════════════════════');
        $this->info('Import Summary:');
        $this->line('═════════════════════════════════════════════════════════');
        $this->line("  Total processed:        {$this->processedCount}");
        $this->line("  ✅ Successfully imported: {$this->importedCount}");

        if ($this->skippedExisting > 0) {
            $this->line("  ⏭️  Skipped (existing):   {$this->skippedExisting}");
        }

        if ($this->skippedUnmappedCustomer > 0) {
            $this->line("  ⚠️  Skipped (unmapped customer): {$this->skippedUnmappedCustomer}");
        }

        if ($this->skippedInvalidData > 0) {
            $this->line("  ❌ Skipped (invalid data): {$this->skippedInvalidData}");
        }

        $this->line('═════════════════════════════════════════════════════════');

        if ($this->skippedUnmappedCustomer > 0) {
            $this->newLine();
            $this->comment('💡 Tip: Some orders were skipped due to unmapped customers.');
            $this->comment('   Link customers to Mossorders users via the mossorders_user_id field.');
        }
    }
}
