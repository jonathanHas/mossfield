<x-app-layout>
    <x-slot name="header">Order {{ $order->order_number }}</x-slot>

    @php
        $listFilters = $listFilters ?? [];
        $orderList = $orderList ?? collect();
        $listTotal = $listTotal ?? $orderList->count();
        $listLimit = $listLimit ?? 50;
        $hasFilters = ! empty($listFilters);
        $availableBatchItems = $availableBatchItems ?? [];
        // Picking statuses render the inline allocation block; others show read-only rows.
        $showAllocation = in_array($order->status, ['confirmed', 'preparing', 'ready'], true);
        $productVariants = $productVariants ?? collect();
        // Items can be added/edited while the order is still open (pending → ready).
        $canAddItems = ! in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true);
        // Status transition hidden fields shared by the PATCH forms below.
        // NB payment_status is deliberately absent: it mirrors the invoice
        // ledger now, and OrderController::update() no longer accepts it.
        $statusFields = [
            'delivery_date' => $order->delivery_date?->format('Y-m-d'),
            'delivery_charge' => number_format((float) $order->delivery_charge, 2, '.', ''),
            'delivery_charge_percent' => $order->delivery_charge_percent,
            'delivery_address' => $order->delivery_address,
            'notes' => $order->notes,
            'customer_reference' => $order->customer_reference,
        ];
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr]">
        <aside class="hidden lg:flex lg:flex-col" style="border-right: 1px solid var(--line-2); max-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto;">
            <div class="px-4 py-3 sticky top-0 z-10" style="background: var(--bg); border-bottom: 1px solid var(--line-2);">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-[13px] font-semibold">Orders</div>
                    <a href="{{ route('orders.index', $listFilters) }}" class="mf-link text-[12px]">All orders →</a>
                </div>
                @if($hasFilters)
                    <div class="mt-1 text-[11.5px]" style="color: var(--muted);">
                        Filtered ·
                        @foreach($listFilters as $k => $v)
                            <span class="font-mono">{{ $k }}={{ $v }}</span>{{ ! $loop->last ? ' · ' : '' }}
                        @endforeach
                    </div>
                @endif
            </div>

            <ul class="flex-1">
                @foreach($orderList as $sibling)
                    @php
                        $isActive = $sibling->id === $order->id;
                        $sibStatusTone = match($sibling->status) {
                            'pending' => 'warn',
                            'confirmed', 'preparing' => 'info',
                            'ready', 'dispatched', 'delivered' => 'accent',
                            'cancelled' => 'danger',
                            default => 'neutral',
                        };
                        $itemSummary = $sibling->orderItems->take(3)->map(function ($i) {
                            return $i->quantity_ordered.'× '.$i->productVariant->picker_label;
                        })->implode(' · ');
                        $extra = $sibling->orderItems->count() - 3;
                    @endphp
                    <li>
                        <a href="{{ route('orders.show', array_merge($listFilters, ['order' => $sibling->id])) }}"
                           class="block px-4 py-3 transition-colors"
                           style="border-bottom: 1px solid var(--line-2); {{ $isActive ? 'background: var(--accent-soft, #f5f3ee); border-left: 3px solid var(--accent, #4a7c4d); padding-left: 13px;' : '' }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <div class="font-mono text-[12px]" style="color: var(--muted);">{{ $sibling->order_number }}</div>
                                <div class="text-[11px]" style="color: var(--muted);">{{ $sibling->created_at->diffForHumans(null, true, true) }}</div>
                            </div>
                            <div class="mt-0.5 text-[13px] font-medium truncate">{{ $sibling->customer->name }}</div>
                            @if($itemSummary)
                                <div class="mt-0.5 text-[11.5px] truncate" style="color: var(--muted);">
                                    {{ $itemSummary }}{{ $extra > 0 ? ' · +'.$extra : '' }}
                                </div>
                            @endif
                            <div class="mt-1.5 flex items-center justify-between gap-2">
                                <span class="mf-tag mf-tag-{{ $sibStatusTone }}">{{ ucfirst($sibling->status) }}</span>
                                @can('see-financials')
                                    <span class="font-mono text-[12px] font-medium">€{{ number_format($sibling->total_amount, 2) }}</span>
                                @endcan
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if($listTotal > $listLimit)
                <div class="px-4 py-3 text-[12px]" style="color: var(--muted); border-top: 1px solid var(--line-2);">
                    Showing {{ $listLimit }} of {{ $listTotal }} ·
                    <a href="{{ route('orders.index', $listFilters) }}" class="mf-link">view all</a>
                </div>
            @endif
        </aside>

        <main class="px-6 py-8">
            <div class="mx-auto max-w-[920px]">

                {{-- Breadcrumb --}}
                <div class="text-[12px] mb-3.5 flex items-center gap-1.5" style="color: var(--muted);">
                    <a href="{{ route('orders.index', $listFilters) }}" style="color: var(--muted); text-decoration: none;">Orders</a>
                    <span style="opacity: .5;">›</span>
                    <span style="color: var(--ink);">{{ $order->order_number }}</span>
                </div>

                {{-- Header --}}
                <div class="flex items-start justify-between gap-6 mb-7">
                    <div class="min-w-0">
                        <h1 class="text-[24px] font-semibold font-mono" style="letter-spacing: -0.02em;">{{ $order->order_number }}</h1>
                        <div class="mt-1.5 text-[13px]" style="color: var(--muted);">
                            <span style="color: var(--ink-2); font-weight: 500;">{{ $order->customer->name }}</span>
                            · ordered {{ $order->order_date->format('j M') }}
                            @if($order->delivery_date)
                                · deliver {{ $order->delivery_date->format('j M') }}
                            @endif
                        </div>
                        @if($order->status === 'cancelled')
                            <span class="mf-tag mf-tag-danger mt-2">Cancelled</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a href="{{ route('orders.index', $listFilters) }}" class="mf-btn-ghost lg:hidden">← All</a>
                        @if($order->hasReachedReady())
                            {{-- The price-free packing slip for the van. Priced paperwork lives in Finance. --}}
                            <a href="{{ route('orders.docket', $order) }}" class="mf-btn-secondary" target="_blank" rel="noopener">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6" /><path d="M8 13h8M8 17h8M8 9h2" /></svg>
                                Docket
                            </a>
                        @endif
                        {{-- The loaded-onto-van tick (OrderPolicy::load — factory can tick
                             too). Dispatch needs it, on this page as on the run sheet. --}}
                        @if($order->status === 'ready')
                            @can('load', $order)
                                <form method="POST" action="{{ route('chilled-runs.toggle-loaded', $order) }}" class="inline mf-landing" id="dispatch">
                                    @csrf
                                    <input type="hidden" name="return" value="order">
                                    <button type="submit" class="mf-btn-secondary" data-loaded-tick
                                            aria-label="{{ $order->loaded_at ? 'Loaded onto van — tap to unmark' : 'Mark loaded onto van' }}">
                                        <span class="mf-run-check{{ $order->loaded_at ? ' on' : '' }}" aria-hidden="true">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                                        </span>
                                        Loaded onto van
                                        @if($order->loaded_at)
                                            <span class="font-mono text-[11px]" style="color: var(--muted);">{{ $order->loaded_at->format('d/m H:i') }}</span>
                                        @endif
                                    </button>
                                </form>
                            @endcan
                        @endif
                        @can('update', $order)
                            {{-- An invoiced order is frozen (blockedByInvoice); say so
                                 rather than offer an Edit that refuses on save (F-34). --}}
                            @if($order->invoice_id)
                                <span class="text-[12px]" style="color: var(--muted);">On invoice {{ $order->invoice?->number }} — void it to edit.</span>
                            @else
                                <a href="{{ route('orders.edit', $order) }}" class="mf-btn-secondary">Edit</a>
                            @endif
                            @if($order->status === 'pending')
                                <form method="POST" action="{{ route('orders.update', $order) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="confirmed">
                                    @foreach($statusFields as $name => $value)
                                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                    @endforeach
                                    <button type="submit" class="mf-btn-primary"
                                        data-confirm-title="Confirm this order?" data-confirm-cancel-label="Not yet" data-confirm="It goes to the picking floor and stock can be allocated to it." data-confirm-label="Confirm order">
                                        Confirm order
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
                                    </button>
                                </form>
                            @elseif($order->status === 'ready' && ! $order->loaded_at)
                                <span class="text-[12px]" style="color: var(--muted);" data-dispatch-needs-loaded>Tick loaded onto van first</span>
                            @elseif($order->status === 'ready')
                                <form method="POST" action="{{ route('orders.dispatch', $order) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="mf-btn-primary"
                                        data-confirm-title="Dispatch this order?" data-confirm-cancel-label="Not yet" data-confirm="Dispatch order {{ $order->order_number }}? {{ $order->customer->isConsolidatedBilling() ? 'It will be billed on the next '.strtolower($order->customer->invoicing_mode->label()).' invoice' : 'The figures are frozen and an invoice is issued' }}, and a dispatch confirmation is emailed to {{ $order->customer->billingEmail() ?? 'no address on file' }}." data-confirm-label="Dispatch">
                                        Mark dispatched
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 3h15v13H1z" /><path d="M16 8h4l3 3v5h-7" /><path d="M5.5 18.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5zM18.5 18.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                                    </button>
                                </form>
                            @elseif($order->status === 'dispatched')
                                <form method="POST" action="{{ route('orders.deliver', $order) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="mf-btn-primary"
                                        data-confirm-title="Mark as delivered?" data-confirm="Mark order {{ $order->order_number }} as delivered?" data-confirm-label="Mark delivered">
                                        Mark delivered
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
                                    </button>
                                </form>
                            @elseif($order->status === 'delivered')
                                <span class="mf-btn-secondary" style="cursor: default;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg>
                                    Delivered
                                </span>
                            @endif
                        @endcan
                    </div>
                </div>

                {{-- Status stepper --}}
                @include('orders.partials.status-stepper', ['order' => $order])

                {{-- Meta strip: Order + Customer --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-10 mb-8 mt-9"
                     style="border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 20px 0 24px;">
                    <div>
                        <div class="mf-eyebrow mb-3">Order</div>
                        <dl class="grid grid-cols-[92px_1fr] gap-y-[7px] gap-x-3 text-[13px]">
                            <dt style="color: var(--muted);">Ordered</dt>
                            <dd class="font-mono">{{ $order->order_date->format('d / m / Y') }}</dd>
                            @if($order->customer_reference)
                                <dt style="color: var(--muted);">Customer ref</dt>
                                <dd class="font-mono">{{ $order->customer_reference }}</dd>
                            @endif
                            <dt style="color: var(--muted);">Delivery</dt>
                            <dd class="font-mono">{{ $order->delivery_date ? $order->delivery_date->format('d / m / Y') : '—' }}</dd>
                            @can('see-financials')
                                {{-- The ONE billing line on the order page. Money actions
                                     (email, payments, void) live under /invoicing. --}}
                                @if($order->isInvoiced())
                                    <dt style="color: var(--muted);">Invoice</dt>
                                    <dd>
                                        <a href="{{ route('invoicing.invoices.show', $order->invoice) }}" class="mf-link font-mono">{{ $order->invoice->number }}</a>
                                        <span class="mf-tag mf-tag-{{ $order->invoice->payment_state_tone }} ml-1">{{ $order->invoice->payment_state_label }}</span>
                                        @unless($order->invoice->hasBeenEmailed())
                                            <span class="text-[11.5px] ml-1" style="color: var(--muted);">not yet sent</span>
                                        @endunless
                                    </dd>
                                @elseif($order->customer->isConsolidatedBilling())
                                    <dt style="color: var(--muted);">Invoice</dt>
                                    <dd style="color: var(--muted);">Billed on the {{ strtolower($order->customer->invoicing_mode->label()) }} invoice</dd>
                                @elseif($order->status === 'ready')
                                    {{-- Not billable yet: an invoice is issued at dispatch (InvoiceService). --}}
                                    <dt style="color: var(--muted);">Invoice</dt>
                                    <dd style="color: var(--muted);">Awaiting dispatch — invoiced when dispatched</dd>
                                @elseif($order->hasReachedReady())
                                    <dt style="color: var(--muted);">Invoice</dt>
                                    <dd>
                                        <span style="color: var(--muted);">Awaiting invoice</span>
                                        · <a href="{{ route('invoicing.uninvoiced.index') }}" class="mf-link">Finance</a>
                                    </dd>
                                @endif
                                @if($order->dispatched_at)
                                    <dt style="color: var(--muted);">Confirmation</dt>
                                    <dd>
                                        @php $dc = $dispatchConfirmation ?? null; @endphp
                                        @if($dc && $dc->status->value === 'sent')
                                            <span style="color: var(--muted);">Sent {{ $dc->sent_at?->format('j M') }} to {{ $dc->recipient }}</span>
                                        @elseif($dc && $dc->status->value === 'queued')
                                            <span style="color: var(--muted);">Queued to {{ $dc->recipient }}</span>
                                        @elseif($dc)
                                            <span class="mf-tag mf-tag-danger">Failed</span>
                                        @elseif(! $order->customer->billingEmail())
                                            <span style="color: var(--muted);">Not sent — no email address</span>
                                        @else
                                            <span style="color: var(--muted);">Not sent</span>
                                        @endif
                                        <a href="{{ route('orders.dispatch-confirmation', $order) }}" class="mf-link ml-1" target="_blank" rel="noopener">view</a>
                                        @can('update', $order)
                                            @if($order->customer->billingEmail() && (! $dc || $dc->status->value === 'failed'))
                                                <form method="POST" action="{{ route('orders.dispatch-confirmation.email', $order) }}" class="inline ml-1">
                                                    @csrf
                                                    <button type="submit" class="mf-link">{{ $dc ? 'Resend' : 'Send' }}</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </dd>
                                @endif
                            @endcan
                            @if($order->dispatched_at)
                                <dt style="color: var(--muted);">Dispatched</dt>
                                <dd class="font-mono">{{ $order->dispatched_at->format('d/m/Y H:i') }}</dd>
                            @endif
                            @if($order->delivered_at)
                                <dt style="color: var(--muted);">Delivered</dt>
                                <dd class="font-mono">{{ $order->delivered_at->format('d/m/Y H:i') }}</dd>
                            @endif
                        </dl>
                    </div>
                    <div>
                        <div class="mf-eyebrow mb-3">Customer</div>
                        <dl class="grid grid-cols-[92px_1fr] gap-y-[7px] gap-x-3 text-[13px]">
                            <dt style="color: var(--muted);">Account</dt>
                            <dd>{{ $order->customer->name }}</dd>
                            <dt style="color: var(--muted);">Contact</dt>
                            <dd>{{ $order->customer->email ?: '—' }}</dd>
                            <dt style="color: var(--muted);">Address</dt>
                            <dd>{{ collect([$order->customer->address, $order->customer->phone])->filter()->implode(' · ') ?: '—' }}</dd>
                            @if($order->delivery_address)
                                <dt style="color: var(--muted);">Deliver to</dt>
                                <dd>{{ $order->delivery_address }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>

                @if($order->notes)
                    <div class="mf-flash mf-flash-warn mb-6">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
                        </svg>
                        <div><b>Notes:</b> {{ $order->notes }}</div>
                    </div>
                @endif

                {{-- Credit limit: warns, never blocks (OPEN_QUESTIONS #5). Open orders only. --}}
                @can('see-financials')
                    @if(in_array($order->status, ['pending', 'confirmed', 'preparing', 'ready'], true)
                        && ($creditWarning = $order->customer->creditWarning((float) $order->total_amount)))
                        <div class="mf-flash mf-flash-warn mb-4" data-credit-warning>
                            <div><b>Over the credit limit.</b> {{ $creditWarning }}</div>
                        </div>
                    @endif
                @endcan

                {{-- Short supply — shown at every status, not just during picking:
                     the invoice and docket carry the shortfall long after the
                     order has shipped. --}}
                @php $shortLines = $order->orderItems->filter(fn ($item) => $item->isShortClosed()); @endphp
                @if($shortLines->isNotEmpty())
                    <div class="mf-flash mf-flash-warn mb-6">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><path d="M12 9v4" /><path d="M12 17h.01" />
                        </svg>
                        <div>
                            <b>Supplied short.</b>
                            {{ $shortLines->sum('quantity_short') }} units across
                            {{ $shortLines->count() }} {{ Str::plural('line', $shortLines->count()) }} weren't supplied.
                            @can('see-financials') They're excluded from the invoice. @endcan
                            <ul class="mt-1.5 ml-4 list-disc text-[12.5px]">
                                @foreach($shortLines as $shortLine)
                                    <li>
                                        {{ $shortLine->productVariant->full_name }} —
                                        {{ $shortLine->quantity_short }} of {{ $shortLine->quantity_ordered }} not supplied
                                        @if(filled($shortLine->short_reason)) ({{ $shortLine->short_reason }}) @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                {{-- Items (#order-items: where a removed line's redirect lands) --}}
                <div id="order-items" class="flex items-center justify-between mb-3.5 mf-landing">
                    <h2 class="text-[15px] font-semibold" style="letter-spacing: -0.01em;">
                        Items <span class="font-normal" style="color: var(--muted);">{{ $order->orderItems->count() }}</span>
                    </h2>
                </div>

                @can('update', $order)
                    @if(in_array($order->status, ['confirmed', 'preparing'], true) && ! $order->isFullyAllocated())
                        <div class="mf-flash mf-flash-success mb-4 items-center justify-between gap-4">
                            <div class="flex items-start gap-2.5">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" /></svg>
                                <div>
                                    <b>Assign stock in one tap.</b>
                                    Auto allocate pulls available stock to every line, oldest batches first.
                                </div>
                            </div>
                            <form method="POST" action="{{ route('order-allocations.auto-allocate', $order) }}" class="inline">
                                @csrf
                                <button type="submit" class="mf-btn-primary whitespace-nowrap"
                                    data-confirm-title="Allocate stock?" data-confirm="Reserves available stock for every line, oldest batches first. Nothing is picked yet." data-confirm-label="Allocate">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" /></svg>
                                    Auto allocate
                                </button>
                            </form>
                        </div>
                    @endif

                    {{-- Handoff cue: fully allocated, awaiting weigh/pack. Complement of the
                         auto-allocate banner above, so the two never show together. --}}
                    @if(in_array($order->status, ['confirmed', 'preparing'], true) && $order->orderItems->isNotEmpty() && $order->isFullyAllocated() && ! $order->isPickingComplete())
                        <div class="mf-flash mf-flash-success mb-4">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="M22 4 12 14.01l-3-3" /></svg>
                            <div>
                                <b>Stock allocated — ready to weigh &amp; pack.</b>
                                The dispatch team can now weigh the cheese and assemble the order.
                                Enter the actual weights and quantities on each line below to complete it.
                            </div>
                        </div>
                    @endif
                @endcan

                <div style="border-bottom: 1px solid var(--line);">
                    @if($showAllocation)
                        @include('orders.partials.allocation-items', ['order' => $order, 'availableBatchItems' => $availableBatchItems])
                    @else
                        @foreach($order->orderItems as $item)
                            @php $isOnlyLine = $order->orderItems->count() <= 1; @endphp
                            <x-order.item-row :item="$item" :order="$order" id="line-{{ $item->id }}" class="mf-landing">
                                <x-slot:summary>
                                    <div class="flex items-center gap-3.5 mt-2.5 text-[12.5px] flex-wrap" style="color: var(--muted);">
                                        @if($item->isFullyFulfilled())
                                            <span class="inline-flex items-center gap-1.5 font-medium" style="color: var(--accent-ink);">
                                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l3 3 5-6"/></svg>
                                                {{ $item->quantity_fulfilled }} of {{ $item->quantity_ordered }} fulfilled
                                            </span>
                                        @elseif($item->isShortClosed())
                                            <span class="inline-flex items-center gap-1.5 font-medium" style="color: var(--warn-ink);">
                                                <span class="inline-block rounded-full" style="width: 7px; height: 7px; background: var(--warn);"></span>
                                                Short {{ $item->quantity_short }} — {{ $item->quantity_fulfilled }} of {{ $item->quantity_ordered }} supplied
                                            </span>
                                        @else
                                            <span class="font-mono">{{ $item->quantity_fulfilled }} of {{ $item->quantity_ordered }} fulfilled</span>
                                        @endif
                                        @if($item->isVariableWeight() && $item->weight_fulfilled_kg > 0)
                                            <span style="color: var(--line);">·</span>
                                            <span class="font-mono" style="color: var(--ink-2);">{{ number_format($item->weight_fulfilled_kg, 3) }} kg</span>
                                        @endif
                                        @can('update', $order)
                                            @if($canAddItems)
                                                <span style="color: var(--line);">·</span>
                                                <form method="POST" action="{{ route('orders.items.update', [$order, $item]) }}" class="inline-flex items-center gap-1.5">
                                                    @csrf @method('PATCH')
                                                    <label for="ordered_{{ $item->id }}" class="text-[11.5px]" style="color: var(--muted);">Ordered:</label>
                                                    <input type="number" name="quantity" id="ordered_{{ $item->id }}" aria-label="Quantity ordered" min="1" value="{{ $item->quantity_ordered }}"
                                                        class="mf-input w-16 px-2 py-0.5 text-[12px]">
                                                    <button type="submit" class="mf-link" style="color: var(--accent-ink);">Update</button>
                                                </form>
                                                <form method="POST" action="{{ route('orders.items.destroy', [$order, $item]) }}" class="inline">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="mf-link" style="color: var(--danger);"
                                                        data-confirm-title="{{ $isOnlyLine ? 'Remove the only line?' : 'Remove this line?' }}" data-confirm="{{ $isOnlyLine ? 'This is the only item — the order is cancelled and any picked stock returns to its batch.' : 'Any picked stock returns to its batch.' }}" data-confirm-label="Remove" data-confirm-danger>{{ $isOnlyLine ? 'Remove (cancels order)' : 'Remove' }}</button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </x-slot:summary>
                            </x-order.item-row>
                        @endforeach
                    @endif
                </div>

                {{-- Add item --}}
                @can('update', $order)
                    @if($canAddItems && $productVariants->isNotEmpty())
                        <div x-data="{ adding: false }" class="mt-3.5">
                            <button type="button" x-show="!adding" @click="adding = true"
                                class="w-full flex items-center justify-center gap-1.5 text-[13px]"
                                style="height: 40px; border: 1px dashed var(--line); border-radius: 8px; color: var(--muted); background: transparent; cursor: pointer;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                                Add item
                            </button>
                            <div x-show="adding" x-cloak style="display: none;" class="rounded-lg p-4 mt-0"
                                style="border: 1px solid var(--line); background: var(--panel);">
                                <form method="POST" action="{{ route('orders.items.store', $order) }}">
                                    @csrf
                                    <div class="grid grid-cols-1 md:grid-cols-[1fr_120px_auto_auto] gap-3 items-end">
                                        <div>
                                            <label class="mf-label" for="add_product_variant_id">Product</label>
                                            <select name="product_variant_id" id="add_product_variant_id" required class="mf-select">
                                                <option value="">Select product…</option>
                                                @foreach($productVariants as $productName => $variants)
                                                    <optgroup label="{{ $productName }}">
                                                        @foreach($variants as $variant)
                                                            <option value="{{ $variant->id }}" {{ old('product_variant_id') == $variant->id ? 'selected' : '' }}>
                                                                {{ $variant->picker_label }} ({{ $variant->price_label }})
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                            @error('product_variant_id')
                                                <p class="mf-error">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label class="mf-label" for="add_quantity">Quantity</label>
                                            {{-- Blank means 1 (F-22): a pre-filled 1 got typed after ("15" → "115"). --}}
                                            <input type="number" name="quantity" id="add_quantity" min="1" value="{{ old('quantity') }}" placeholder="1" class="mf-input">
                                            @error('quantity')
                                                <p class="mf-error">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <button type="submit" class="mf-btn-primary w-full justify-center">Add item</button>
                                        </div>
                                        <div>
                                            <button type="button" @click="adding = false" class="mf-btn-ghost w-full justify-center">Cancel</button>
                                        </div>
                                    </div>
                                    @if($order->status === 'ready')
                                        <p class="text-[11.5px] mt-2" style="color: var(--muted);">
                                            Adding to a ready order reopens picking — it returns to <b>Preparing</b> until the new line is fulfilled.
                                        </p>
                                    @endif
                                </form>
                            </div>
                        </div>
                    @endif
                @endcan

                {{-- Totals --}}
                @can('see-financials')
                    <div class="flex flex-col items-end gap-1 mt-8 text-[13px]">
                        <div class="grid grid-cols-[120px_80px] gap-x-6">
                            <div style="color: var(--muted);">Subtotal</div>
                            <div class="text-right font-mono" style="color: var(--ink-2);">€{{ number_format($order->subtotal, 2) }}</div>
                        </div>
                        @if($order->delivery_charge > 0)
                            <div class="grid grid-cols-[120px_80px] gap-x-6">
                                <div style="color: var(--muted);">{{ $order->delivery_charge_percent ? 'Delivery charge ('.rtrim(rtrim(number_format($order->delivery_charge_percent, 2), '0'), '.').'%)' : 'Delivery charge' }}</div>
                                <div class="text-right font-mono" style="color: var(--ink-2);">€{{ number_format($order->delivery_charge_net, 2) }}</div>
                            </div>
                        @endif
                        <div class="grid grid-cols-[120px_80px] gap-x-6">
                            <div style="color: var(--muted);">{{ $order->delivery_charge > 0 ? 'VAT (23%)' : 'Tax' }}</div>
                            <div class="text-right font-mono" style="color: var(--ink-2);">€{{ number_format($order->tax_amount, 2) }}</div>
                        </div>
                        <div class="grid grid-cols-[120px_80px] gap-x-6 font-semibold text-[15px] mt-1.5 pt-2"
                            style="border-top: 1px solid var(--line); width: 224px;">
                            <div>Total</div>
                            <div class="text-right font-mono">€{{ number_format($order->total_amount, 2) }}</div>
                        </div>
                    </div>
                @endcan

                {{-- Footer --}}
                <div class="flex justify-between items-center mt-8 pt-4" style="border-top: 1px solid var(--line-2);">
                    <div class="text-[12px]" style="color: var(--muted);">
                        Created {{ $order->created_at->format('d/m/Y H:i') }}
                        @if($order->updated_at != $order->created_at)
                            · Last updated {{ $order->updated_at->format('d/m/Y H:i') }}
                        @endif
                    </div>
                    @can('update', $order)
                        @if($order->canBeCancelled())
                            <form method="POST" action="{{ route('orders.update', $order) }}" class="inline">
                                @csrf @method('PATCH')
                                <input type="hidden" name="status" value="cancelled">
                                @foreach($statusFields as $name => $value)
                                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                @endforeach
                                <button type="submit" class="mf-link" style="color: var(--danger);"
                                    data-confirm-title="Cancel this order?" data-confirm-cancel-label="Keep order"
                                    data-confirm="Reserved and picked stock goes back to its batch; the order stays on record as cancelled." data-confirm-label="Cancel order" data-confirm-danger>
                                    Cancel order
                                </button>
                            </form>
                        @endif
                    @endcan
                </div>
            </div>
        </main>
    </div>
</x-app-layout>
