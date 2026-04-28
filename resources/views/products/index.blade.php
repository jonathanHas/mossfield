<x-app-layout>
    @php
        $typeMeta = [
            'milk'    => ['label' => 'Milk',    'tone' => 'info'],
            'yoghurt' => ['label' => 'Yoghurt', 'tone' => 'accent'],
            'cheese'  => ['label' => 'Cheese',  'tone' => 'warn'],
        ];
    @endphp

    <x-slot name="header">Products</x-slot>

    <div class="px-6 py-5">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Products</h1>
                <div class="mt-0.5 text-[13px]" style="color: var(--muted);">Catalog grouped by product type.</div>
            </div>
            @can('create', App\Models\Product::class)
                <a href="{{ route('products.create') }}" class="mf-btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                    New product
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error">{{ session('error') }}</div>
        @endif

        @forelse ($products as $type => $typeProducts)
            @php
                $meta = $typeMeta[$type] ?? ['label' => ucfirst($type), 'tone' => 'neutral'];
                $productCount = $typeProducts->count();
                $variantCount = $typeProducts->sum(fn ($p) => $p->variants->count());
            @endphp

            <section class="mb-6">
                <div class="flex items-center gap-3 mb-3 pb-2" style="border-bottom: 1px solid var(--line);">
                    <h2 class="text-[16px] font-display font-medium">{{ $meta['label'] }}</h2>
                    <span class="mf-tag mf-tag-{{ $meta['tone'] }}">
                        {{ $productCount }} {{ Str::plural('product', $productCount) }} · {{ $variantCount }} {{ Str::plural('variant', $variantCount) }}
                    </span>
                </div>

                <div class="space-y-3">
                    @foreach ($typeProducts as $product)
                        <div class="mf-panel">
                            <div class="flex flex-wrap items-start gap-4 px-4 py-3" style="background: var(--bg); border-bottom: 1px solid var(--line-2);">
                                @if($product->image_url)
                                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-14 w-14 object-cover rounded flex-shrink-0" style="border: 1px solid var(--line);" />
                                @else
                                    <div class="h-14 w-14 rounded flex items-center justify-center text-[10px] flex-shrink-0" style="background: var(--panel); border: 1px solid var(--line); color: var(--faint);">No image</div>
                                @endif

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="text-[15px] font-semibold">{{ $product->name }}</h3>
                                        @if($product->is_active)
                                            <span class="mf-tag mf-tag-accent">Active</span>
                                        @else
                                            <span class="mf-tag mf-tag-danger">Inactive</span>
                                        @endif
                                        @if($product->maturation_days)
                                            <span class="mf-tag mf-tag-neutral">{{ $product->maturation_days }}d maturation</span>
                                        @endif
                                    </div>
                                    @if($product->description)
                                        <p class="mt-1 text-[12.5px]" style="color: var(--muted);">{{ Str::limit($product->description, 140) }}</p>
                                    @endif
                                    <p class="mt-1 text-[11.5px]" style="color: var(--faint);">
                                        {{ $product->variants->count() }} {{ Str::plural('variant', $product->variants->count()) }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-2 text-[13px] flex-shrink-0">
                                    <a href="{{ route('products.show', $product) }}" class="mf-link">View</a>
                                    <span style="color: var(--faint);">·</span>
                                    <a href="{{ route('products.edit', $product) }}" class="mf-link">Edit</a>
                                    @can('delete', $product)
                                        <span style="color: var(--faint);">·</span>
                                        <form action="{{ route('products.destroy', $product) }}" method="POST" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="mf-link" style="color: var(--danger);"
                                                    onclick="return confirm('Are you sure?')">Delete</button>
                                        </form>
                                    @endcan
                                </div>
                            </div>

                            <div class="px-4 py-3">
                                <div class="flex justify-between items-center mb-2">
                                    <h4 class="text-[11.5px] font-medium uppercase" style="color: var(--muted); letter-spacing: 0.4px;">Variants</h4>
                                    @can('update', $product)
                                        <a href="{{ route('products.variants.create', $product) }}" class="mf-btn-secondary text-[12px] px-2.5 py-1">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                                            Add variant
                                        </a>
                                    @endcan
                                </div>

                                @if($product->variants->count() > 0)
                                    <div class="overflow-x-auto rounded-md" style="border: 1px solid var(--line);">
                                        <table class="w-full border-collapse text-[13px]">
                                            <thead>
                                                <tr>
                                                    <th class="mf-th">Name</th>
                                                    <th class="mf-th">Size</th>
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
                                                                    <img src="{{ $variant->image_url }}" alt="{{ $variant->name }}" class="h-7 w-7 object-cover rounded flex-shrink-0" style="border: 1px solid var(--line);" />
                                                                @endif
                                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                                    <span>{{ $variant->name }}</span>
                                                                    @if($variant->is_variable_weight)
                                                                        <span class="mf-tag mf-tag-warn" title="Weighed at fulfillment">Var. wt</span>
                                                                    @endif
                                                                    @if($variant->is_priced_by_weight)
                                                                        <span class="mf-tag mf-tag-info" title="Priced per kilogram">€/kg</span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="mf-td font-mono" style="color: var(--muted);">{{ $variant->size }}</td>
                                                        <td class="mf-td font-mono" style="color: var(--muted);">{{ $variant->weight_kg ? $variant->weight_kg . ' kg' : '—' }}</td>
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
                                                                <form action="{{ route('products.variants.destroy', [$product, $variant]) }}" method="POST" class="inline">
                                                                    @csrf @method('DELETE')
                                                                    <button type="submit" class="mf-link" style="color: var(--danger);"
                                                                            onclick="return confirm('Are you sure you want to delete this variant?')">Delete</button>
                                                                </form>
                                                            @endcan
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="text-[12.5px] text-center py-4 rounded-md" style="color: var(--muted); border: 1px dashed var(--line);">
                                        No variants yet. <a href="{{ route('products.variants.create', $product) }}" class="mf-link">Add a variant</a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="mf-panel p-8 text-center" style="color: var(--muted);">
                No products found. <a href="{{ route('products.create') }}" class="mf-link">Create your first product</a>
            </div>
        @endforelse
    </div>
</x-app-layout>
