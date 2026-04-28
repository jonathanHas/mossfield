<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OnlineOrderImportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class OnlineOrdersController extends Controller
{
    /**
     * Display the online orders dashboard.
     */
    public function index(Request $request): View
    {
        // Get import statistics
        $totalImported = Order::whereNotNull('mossorders_order_id')->count();
        $pendingImported = Order::whereNotNull('mossorders_order_id')
            ->where('status', 'pending')
            ->count();
        $lastImportedOrder = Order::whereNotNull('mossorders_order_id')
            ->latest('created_at')
            ->first();

        // Get customers with online accounts
        $linkedCustomers = Customer::whereNotNull('mossorders_user_id')->count();
        $totalCustomers = Customer::count();

        // Check API configuration status
        $apiConfigured = config('services.mossorders.base_url') && config('services.mossorders.api_token');

        // Recent imported orders
        $recentImports = Order::with('customer')
            ->whereNotNull('mossorders_order_id')
            ->latest('created_at')
            ->take(10)
            ->get();

        return view('online-orders.index', compact(
            'totalImported',
            'pendingImported',
            'lastImportedOrder',
            'linkedCustomers',
            'totalCustomers',
            'apiConfigured',
            'recentImports'
        ));
    }

    /**
     * Preview orders from the Mossorders API without importing.
     */
    public function preview(Request $request, OnlineOrderImportService $importService): View
    {
        $since = $request->input('since');
        $orders = [];
        $error = null;
        $apiConfigured = config('services.mossorders.base_url') && config('services.mossorders.api_token');

        if (! $apiConfigured) {
            $error = 'Mossorders API is not configured. Please set MOSSORDERS_BASE_URL and MOSSORDERS_API_TOKEN in your .env file.';
        } else {
            try {
                $orders = $importService->fetchOrders($since);

                // Enrich orders with import status
                foreach ($orders as &$order) {
                    $mossordersOrderId = $order['mossorders_order_id'] ?? null;
                    $order['already_imported'] = $mossordersOrderId
                        ? Order::where('mossorders_order_id', $mossordersOrderId)->exists()
                        : false;

                    // Check customer mapping
                    $mossordersUserId = $order['customer']['mossorders_user_id'] ?? null;
                    $order['customer_mapped'] = $mossordersUserId
                        ? Customer::where('mossorders_user_id', $mossordersUserId)->exists()
                        : false;

                    if ($mossordersUserId && $order['customer_mapped']) {
                        $order['office_customer'] = Customer::where('mossorders_user_id', $mossordersUserId)->first();
                    }
                }
            } catch (\Exception $e) {
                $error = 'Failed to fetch orders from Mossorders: '.$e->getMessage();
                Log::error('Online orders preview error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return view('online-orders.preview', compact('orders', 'error', 'since', 'apiConfigured'));
    }

    /**
     * Import orders from the Mossorders API.
     */
    public function import(Request $request, OnlineOrderImportService $importService)
    {
        $since = $request->input('since');
        $selectedOrderIds = $request->input('order_ids', []);

        // Validate API configuration
        if (! config('services.mossorders.base_url') || ! config('services.mossorders.api_token')) {
            return redirect()->route('online-orders.index')
                ->with('error', 'Mossorders API is not configured.');
        }

        try {
            $orders = $importService->fetchOrders($since);
        } catch (\Exception $e) {
            return redirect()->route('online-orders.preview')
                ->with('error', 'Failed to fetch orders: '.$e->getMessage());
        }

        $stats = [
            'processed' => 0,
            'imported' => 0,
            'skipped_existing' => 0,
            'skipped_unmapped' => 0,
            'skipped_invalid' => 0,
            'skipped_not_selected' => 0,
        ];

        foreach ($orders as $payload) {
            $stats['processed']++;
            $mossordersOrderId = $payload['mossorders_order_id'] ?? null;

            // Skip if not in selected orders (when specific orders are selected)
            if (! empty($selectedOrderIds) && ! in_array($mossordersOrderId, $selectedOrderIds)) {
                $stats['skipped_not_selected']++;

                continue;
            }

            // Validate required fields
            if (! $mossordersOrderId) {
                $stats['skipped_invalid']++;

                continue;
            }

            // Check if already imported
            if (Order::where('mossorders_order_id', $mossordersOrderId)->exists()) {
                $stats['skipped_existing']++;

                continue;
            }

            // Resolve customer
            $mossordersUserId = $payload['customer']['mossorders_user_id'] ?? null;
            if (! $mossordersUserId) {
                $stats['skipped_unmapped']++;

                continue;
            }

            $customer = Customer::where('mossorders_user_id', $mossordersUserId)->first();
            if (! $customer) {
                $stats['skipped_unmapped']++;

                continue;
            }

            // Validate items
            if (empty($payload['items'])) {
                $stats['skipped_invalid']++;

                continue;
            }

            // Import the order
            try {
                DB::transaction(function () use ($payload, $customer, $mossordersOrderId) {
                    $orderDate = isset($payload['placed_at'])
                        ? Carbon::parse($payload['placed_at'])
                        : now();

                    $order = Order::create([
                        'mossorders_order_id' => $mossordersOrderId,
                        'customer_id' => $customer->id,
                        'order_date' => $orderDate,
                        'delivery_date' => null,
                        'status' => 'pending',
                        'payment_status' => 'pending',
                        'subtotal' => $payload['totals']['subtotal'] ?? 0,
                        'tax_amount' => $payload['totals']['tax'] ?? 0,
                        'total_amount' => $payload['totals']['grand_total'] ?? 0,
                        'delivery_address' => null,
                        'notes' => $payload['notes'] ?? 'Imported from Mossorders order '.($payload['order_number'] ?? "MSF-{$mossordersOrderId}"),
                    ]);

                    foreach ($payload['items'] as $itemPayload) {
                        $officeVariantId = $itemPayload['office_variant_id'] ?? null;
                        if (! $officeVariantId) {
                            continue;
                        }

                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_variant_id' => $officeVariantId,
                            'quantity_ordered' => $itemPayload['quantity'] ?? 0,
                            'quantity_allocated' => 0,
                            'quantity_fulfilled' => 0,
                            'unit_price' => $itemPayload['unit_price'] ?? 0,
                            'notes' => $itemPayload['product_name'] ?? null,
                        ]);
                    }
                });

                $stats['imported']++;
            } catch (\Exception $e) {
                Log::error('Order import failed', [
                    'mossorders_order_id' => $mossordersOrderId,
                    'error' => $e->getMessage(),
                ]);
                $stats['skipped_invalid']++;
            }
        }

        $message = "Import complete: {$stats['imported']} imported";
        if ($stats['skipped_existing'] > 0) {
            $message .= ", {$stats['skipped_existing']} already existed";
        }
        if ($stats['skipped_unmapped'] > 0) {
            $message .= ", {$stats['skipped_unmapped']} skipped (unmapped customer)";
        }
        if ($stats['skipped_invalid'] > 0) {
            $message .= ", {$stats['skipped_invalid']} skipped (invalid data)";
        }

        return redirect()->route('online-orders.index')
            ->with('success', $message);
    }
}
