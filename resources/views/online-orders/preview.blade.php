<x-app-layout>
    <x-slot name="header">Preview online orders</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Preview online orders</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Fetch and inspect orders from Mossorders before importing.</div>
            </div>
            <a href="{{ route('online-orders.index') }}" class="mf-btn-ghost">← Back</a>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error">{{ session('error') }}</div>
        @endif
        @if ($error)
            <div class="mf-flash mf-flash-error">{{ $error }}</div>
        @endif

        <div class="mf-panel mb-4">
            <form method="GET" action="{{ route('online-orders.preview') }}" class="flex gap-3 items-end p-4">
                <div class="flex-1">
                    <label for="since" class="mf-label">Orders since</label>
                    <input type="datetime-local" name="since" id="since" value="{{ $since }}" class="mf-input">
                    <p class="text-[12px] mt-1" style="color: var(--muted);">Leave empty to fetch all available orders.</p>
                </div>
                <button type="submit" class="mf-btn-primary">Fetch orders</button>
                @if($since)
                    <a href="{{ route('online-orders.preview') }}" class="mf-btn-ghost">Clear</a>
                @endif
            </form>
        </div>

        @if(!$apiConfigured)
            <div class="mf-flash mf-flash-warn mb-4">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                    <path d="M12 9v4" /><path d="M12 17h.01" />
                </svg>
                <div>
                    <strong>API configuration required.</strong>
                    To fetch orders from Mossorders, set the API settings in your <code class="font-mono">.env</code>:
                    <pre class="mt-2 p-2 rounded text-[12px] font-mono" style="background: rgba(255,255,255,0.5);">MOSSORDERS_BASE_URL=https://your-mossorders-url.com
MOSSORDERS_API_TOKEN=your-api-token</pre>
                </div>
            </div>
        @elseif(count($orders) > 0)
            <form method="POST" action="{{ route('online-orders.import') }}" id="importForm">
                @csrf
                @if($since)
                    <input type="hidden" name="since" value="{{ $since }}">
                @endif

                <div class="mf-panel mb-4">
                    <div class="px-4 py-3 flex justify-between items-center">
                        <div class="flex flex-wrap gap-6">
                            <div>
                                <span class="font-mono text-[20px] font-semibold">{{ count($orders) }}</span>
                                <span class="text-[12.5px] ml-1" style="color: var(--muted);">orders found</span>
                            </div>
                            <div>
                                <span class="font-mono text-[20px] font-semibold" style="color: var(--accent-ink);">{{ collect($orders)->where('customer_mapped', true)->where('already_imported', false)->count() }}</span>
                                <span class="text-[12.5px] ml-1" style="color: var(--muted);">ready to import</span>
                            </div>
                            <div>
                                <span class="font-mono text-[20px] font-semibold" style="color: var(--warn-ink);">{{ collect($orders)->where('already_imported', true)->count() }}</span>
                                <span class="text-[12.5px] ml-1" style="color: var(--muted);">already imported</span>
                            </div>
                            <div>
                                <span class="font-mono text-[20px] font-semibold" style="color: var(--danger);">{{ collect($orders)->where('customer_mapped', false)->count() }}</span>
                                <span class="text-[12.5px] ml-1" style="color: var(--muted);">unmapped</span>
                            </div>
                        </div>
                        <button type="submit" class="mf-btn-primary">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />
                            </svg>
                            Import all ready
                        </button>
                    </div>
                </div>

                <div class="mf-panel">
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-[13px]">
                            <thead>
                                <tr>
                                    <th class="mf-th">Order</th>
                                    <th class="mf-th">Customer</th>
                                    <th class="mf-th">Items</th>
                                    <th class="mf-th text-right">Total</th>
                                    <th class="mf-th">Date</th>
                                    <th class="mf-th">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                    <tr style="border-top: 1px solid var(--line-2); {{ $order['already_imported'] ? 'background: var(--bg);' : '' }}">
                                        <td class="mf-td font-mono text-[12.5px]">
                                            <div>{{ $order['order_number'] ?? 'MSF-' . ($order['mossorders_order_id'] ?? 'N/A') }}</div>
                                            <div class="text-[11px] mt-0.5" style="color: var(--muted);">Mossorders ID: {{ $order['mossorders_order_id'] ?? 'N/A' }}</div>
                                        </td>
                                        <td class="mf-td">
                                            <div class="font-medium">{{ $order['customer']['name'] ?? 'Unknown' }}</div>
                                            <div class="text-[11.5px] mt-0.5" style="color: var(--muted);">{{ $order['customer']['email'] ?? '' }}</div>
                                            @if($order['customer_mapped'])
                                                <span class="mf-tag mf-tag-accent mt-1">Linked: {{ $order['office_customer']->name ?? 'Customer' }}</span>
                                            @else
                                                <span class="mf-tag mf-tag-danger mt-1">Unmapped (ID: {{ $order['customer']['mossorders_user_id'] ?? 'N/A' }})</span>
                                            @endif
                                        </td>
                                        <td class="mf-td">
                                            @if(isset($order['items']) && is_array($order['items']))
                                                <div class="font-mono">{{ count($order['items']) }} item(s)</div>
                                                <div class="text-[11.5px] mt-0.5 max-w-xs truncate" style="color: var(--muted);">
                                                    @foreach(array_slice($order['items'], 0, 2) as $item)
                                                        {{ $item['quantity'] ?? 1 }}× {{ $item['product_name'] ?? 'Unknown' }}<br>
                                                    @endforeach
                                                    @if(count($order['items']) > 2)
                                                        <span style="color: var(--faint);">+{{ count($order['items']) - 2 }} more…</span>
                                                    @endif
                                                </div>
                                            @else
                                                <span style="color: var(--faint);">No items</span>
                                            @endif
                                        </td>
                                        <td class="mf-td font-mono text-right">
                                            @if(isset($order['totals']['grand_total']))
                                                €{{ number_format($order['totals']['grand_total'], 2) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="mf-td font-mono" style="color: var(--muted);">
                                            @if(isset($order['placed_at']))
                                                {{ \Carbon\Carbon::parse($order['placed_at'])->format('d M Y H:i') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="mf-td">
                                            @if($order['already_imported'])
                                                <span class="mf-tag mf-tag-neutral">Already imported</span>
                                            @elseif($order['customer_mapped'])
                                                <span class="mf-tag mf-tag-accent">Ready</span>
                                            @else
                                                <span class="mf-tag mf-tag-danger">Cannot import</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @elseif($apiConfigured)
            <div class="mf-panel p-10 text-center">
                <h3 class="text-[14px] font-semibold">No orders available</h3>
                <p class="mt-1 text-[13px]" style="color: var(--muted);">
                    No orders were returned from the Mossorders API.
                    @if($since) Try clearing the date filter or check back later. @endif
                </p>
            </div>
        @endif

        <div class="mt-4 mf-flash" style="background: var(--info-soft); border-color: oklch(0.92 0.04 235); color: var(--info);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" />
            </svg>
            <div>
                <strong>Import tips.</strong>
                <ul class="mt-1 list-disc list-inside text-[12.5px] space-y-0.5">
                    <li><b>Unmapped customers:</b> link customers to Mossorders accounts via the <a href="{{ route('customers.index') }}" class="underline">Customers</a> page.</li>
                    <li><b>Already imported:</b> skipped automatically (idempotent import).</li>
                    <li><b>CLI:</b> <code class="font-mono">php artisan mossfield:import-online-orders</code></li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
