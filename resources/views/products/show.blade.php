<x-app-layout>
    <x-slot name="header">{{ $product->name }}</x-slot>

    @php
        $typeTone = match($product->type) {
            'milk' => 'info',
            'yoghurt' => 'accent',
            'cheese' => 'warn',
            default => 'neutral',
        };
        $listFilters = $listFilters ?? [];
        $productList = $productList ?? collect();
        $listTotal = $listTotal ?? $productList->count();
        $listLimit = $listLimit ?? 50;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-[320px_1fr]">
        <aside class="hidden lg:flex lg:flex-col" style="border-right: 1px solid var(--line-2); max-height: calc(100vh - var(--header-height, 64px)); overflow-y: auto;">
            @include('products._sibling_list', ['mode' => 'show'])
        </aside>

        <main class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <div class="text-[12px] font-mono" style="color: var(--muted);">Product</div>
                <h1 class="mt-0.5 text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">{{ $product->name }}</h1>
                <div class="mt-1 flex items-center gap-2">
                    <span class="mf-tag mf-tag-{{ $typeTone }}">{{ ucfirst($product->type) }}</span>
                    @if($product->is_active)
                        <span class="mf-tag mf-tag-accent">Active</span>
                    @else
                        <span class="mf-tag mf-tag-danger">Inactive</span>
                    @endif
                    @if($product->maturation_days)
                        <span class="mf-tag mf-tag-neutral">{{ $product->maturation_days }}d maturation</span>
                    @endif
                </div>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('products.index', $listFilters) }}" class="mf-btn-ghost lg:hidden">← All products</a>
                @can('update', $product)
                    <a href="{{ route('products.edit', array_merge($listFilters, ['product' => $product->id])) }}" class="mf-btn-secondary">Edit</a>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="text-[13px] font-semibold">Product information</div>
            </div>
            <div class="px-4 py-3">
                @if($product->image_url)
                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="mb-4 max-h-60 rounded object-cover" style="border: 1px solid var(--line);" />
                @endif

                <dl class="text-[13px] grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2">
                    <div class="grid grid-cols-[140px_1fr] gap-y-2">
                        <dt style="color: var(--muted);">Name</dt>
                        <dd>{{ $product->name }}</dd>
                        <dt style="color: var(--muted);">Type</dt>
                        <dd><span class="mf-tag mf-tag-{{ $typeTone }}">{{ ucfirst($product->type) }}</span></dd>
                        <dt style="color: var(--muted);">Status</dt>
                        <dd>
                            @if($product->is_active)
                                <span class="mf-tag mf-tag-accent">Active</span>
                            @else
                                <span class="mf-tag mf-tag-danger">Inactive</span>
                            @endif
                        </dd>
                    </div>
                    <div class="grid grid-cols-[140px_1fr] gap-y-2">
                        @if($product->maturation_days)
                            <dt style="color: var(--muted);">Maturation</dt>
                            <dd class="font-mono">{{ $product->maturation_days }} days</dd>
                        @endif
                        @if($product->description)
                            <dt style="color: var(--muted);">Description</dt>
                            <dd>{{ $product->description }}</dd>
                        @endif
                    </div>
                </dl>
            </div>
        </div>

        <div class="mf-panel mb-4">
            <div class="mf-panel-header">
                <div class="flex-1">
                    <div class="text-[13px] font-semibold">Variants</div>
                </div>
                @can('update', $product)
                    <a href="{{ route('products.variants.create', $product) }}" class="mf-btn-secondary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                        Add variant
                    </a>
                @endcan
            </div>

            @if($product->variants->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Name</th>
                                <th class="mf-th">Size</th>
                                <th class="mf-th">Unit</th>
                                <th class="mf-th">Weight</th>
                                <th class="mf-th">Price</th>
                                <th class="mf-th">Stock</th>
                                <th class="mf-th">Status</th>
                                <th class="mf-th text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($product->variants as $variant)
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td">
                                        <div class="flex items-center gap-2">
                                            @if($variant->image_url)
                                                <img src="{{ $variant->image_url }}" alt="{{ $variant->name }}" class="h-8 w-8 object-cover rounded flex-shrink-0" style="border: 1px solid var(--line);" />
                                            @endif
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span>{{ $variant->name }}</span>
                                                @if($variant->is_variable_weight)
                                                    <span class="mf-tag mf-tag-warn">Var. wt</span>
                                                @endif
                                                @if($variant->is_priced_by_weight)
                                                    <span class="mf-tag mf-tag-info">€/kg</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $variant->size }}</td>
                                    <td class="mf-td" style="color: var(--muted);">{{ $variant->unit }}</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $variant->weight_kg ?? '—' }}</td>
                                    <td class="mf-td font-mono font-medium">{{ $variant->price_label }}</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $variant->total_stock }}</td>
                                    <td class="mf-td">
                                        @if($variant->is_active)
                                            <span class="mf-tag mf-tag-accent">Active</span>
                                        @else
                                            <span class="mf-tag mf-tag-danger">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="mf-td text-right whitespace-nowrap">
                                        @can('update', $product)
                                            <a href="{{ route('products.variants.edit', [$product, $variant]) }}" class="mf-link">Edit</a>
                                            <span style="color: var(--faint);">·</span>
                                            <a href="{{ route('products.variants.create', ['product' => $product, 'from' => $variant->id]) }}" class="mf-link">Duplicate</a>
                                            <span style="color: var(--faint);">·</span>
                                            <form action="{{ route('products.variants.destroy', [$product, $variant]) }}" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this variant?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="mf-link" style="color: var(--danger);">Delete</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-8 text-center" style="color: var(--muted);">No variants created yet. Add a variant to start tracking stock.</div>
            @endif
        </div>

        <div class="mf-panel">
            <div class="mf-panel-header">
                <div class="flex-1">
                    <div class="text-[13px] font-semibold">Recent batches</div>
                </div>
                @can('create', App\Models\Batch::class)
                    <a href="{{ route('batches.create', ['product_id' => $product->id]) }}" class="mf-btn-secondary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                        New batch
                    </a>
                @endcan
            </div>

            @if($product->batches->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead>
                            <tr>
                                <th class="mf-th">Batch code</th>
                                <th class="mf-th">Production date</th>
                                <th class="mf-th">Total qty</th>
                                <th class="mf-th">Ready date</th>
                                <th class="mf-th">Remaining</th>
                                <th class="mf-th">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($product->batches->take(10) as $batch)
                                @php
                                    $batchTone = match($batch->status) {
                                        'active' => 'accent',
                                        'sold_out' => 'danger',
                                        default => 'neutral',
                                    };
                                @endphp
                                <tr style="border-top: 1px solid var(--line-2);">
                                    <td class="mf-td font-mono">{{ $batch->batch_code }}</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">{{ $batch->production_date->format('d/m/Y') }}</td>
                                    <td class="mf-td font-mono">{{ $batch->total_quantity_kg }} kg</td>
                                    <td class="mf-td font-mono" style="color: var(--muted);">
                                        @if($batch->ready_date)
                                            {{ $batch->ready_date->format('d/m/Y') }}
                                            @if(!$batch->isReadyToSell())
                                                <span class="text-[11.5px]" style="color: var(--warn-ink);"> ({{ $batch->ready_date->diffForHumans() }})</span>
                                            @endif
                                        @else
                                            Ready now
                                        @endif
                                    </td>
                                    <td class="mf-td font-mono">{{ $batch->remaining_stock }}</td>
                                    <td class="mf-td"><span class="mf-tag mf-tag-{{ $batchTone }}">{{ ucfirst(str_replace('_', ' ', $batch->status)) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-8 text-center" style="color: var(--muted);">No batches yet. Create the first one to start production tracking.</div>
            @endif
        </div>
        </main>
    </div>
</x-app-layout>
