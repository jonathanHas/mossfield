<?php

namespace App\Console\Commands;

use App\Services\OnlineOrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Preview orders from the Mossorders online portal without importing them.
 *
 * This is a READ-ONLY command that fetches and displays orders from the
 * external Mossorders API without creating or modifying any local records.
 */
class PreviewOnlineOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mossfield:preview-online-orders {--since= : Only fetch orders updated since this ISO8601 datetime}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and preview orders from the Mossorders portal without importing them';

    /**
     * Execute the console command.
     */
    public function handle(OnlineOrderImportService $importService): int
    {
        $this->info('Fetching orders from Mossorders online portal...');
        $this->newLine();

        // Get the optional 'since' parameter
        $since = $this->option('since');

        if ($since) {
            $this->info("Filtering orders updated since: {$since}");
            $this->newLine();
        }

        // Fetch orders from the Mossorders API
        $orders = $importService->fetchOrders($since);

        // Handle empty result
        if (empty($orders)) {
            $this->warn('No online orders returned.');
            $this->info('This could mean:');
            $this->line('  - No orders exist in the online portal');
            $this->line('  - The API request failed (check logs)');
            $this->line('  - Configuration is incorrect (check .env)');

            Log::info('Mossorders preview command completed with no orders', [
                'since' => $since,
            ]);

            return 0;
        }

        // Display summary
        $orderCount = count($orders);
        $this->info("Successfully fetched {$orderCount} order(s) from Mossorders:");
        $this->newLine();

        // Display each order with JSON formatting (flexible structure)
        foreach ($orders as $index => $order) {
            $orderNumber = $index + 1;
            $this->line("─────────────────────────────────────────────────────────");
            $this->line("Order #{$orderNumber}:");
            $this->line("─────────────────────────────────────────────────────────");

            // Pretty print the order data
            $this->line(json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
        }

        $this->line("═════════════════════════════════════════════════════════");
        $this->info("Total orders fetched: {$orderCount}");
        $this->line("═════════════════════════════════════════════════════════");

        // Log summary
        Log::info('Mossorders preview command completed successfully', [
            'order_count' => $orderCount,
            'since' => $since,
            'sample_order_keys' => ! empty($orders) ? array_keys($orders[0]) : [],
        ]);

        $this->newLine();
        $this->comment('ℹ️  This was a preview only - no local orders were created.');

        return 0;
    }
}
