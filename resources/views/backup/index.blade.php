<x-app-layout>
    <x-slot name="header">Backup &amp; Restore</x-slot>

    @php
        $tableLabels = [
            'users' => 'Users',
            'products' => 'Products',
            'delivery_runs' => 'Delivery runs',
            'product_variants' => 'Product variants',
            'customers' => 'Customers',
            'batches' => 'Batches',
            'batch_items' => 'Batch items (stock)',
            'orders' => 'Orders',
            'customer_special_prices' => 'Customer special prices',
            'order_items' => 'Order items',
            'cheese_cutting_logs' => 'Cheese cutting logs',
            'cheese_conversion_logs' => 'Cheese conversion logs',
            'order_allocations' => 'Order allocations',
        ];
        $totalRows = array_sum($rowCounts);
    @endphp

    <div class="px-6 py-5 max-w-4xl">
        <div class="mb-4">
            <h1 class="text-[22px] font-display font-medium" style="letter-spacing: -0.4px;">Backup &amp; Restore</h1>
            <div class="mt-0.5 text-[13px]" style="color: var(--muted);">
                Download a full snapshot of the database, or restore from a previous backup file.
            </div>
        </div>

        @if (session('success'))
            <div class="mf-flash mf-flash-success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mf-flash mf-flash-error">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mf-flash mf-flash-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        {{-- Backup --}}
        <div class="mf-panel mb-5">
            <div class="p-4" style="border-bottom: 1px solid var(--line-2);">
                <h2 class="text-[15px] font-medium">Download backup</h2>
                <div class="mt-0.5 text-[12.5px]" style="color: var(--muted);">
                    A single encrypted <span class="font-mono">.mfbackup</span> file with every business
                    table <strong>and product images</strong>. Set a password to protect it — you'll need
                    the same password to restore.
                </div>

                <form action="{{ route('backup.download') }}" method="POST" class="mt-3 flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="download_password" class="mf-label">Backup password</label>
                        <input type="password" name="password" id="download_password" required minlength="8"
                               autocomplete="new-password" placeholder="At least 8 characters"
                               class="mf-input" style="max-width: 260px;">
                    </div>
                    <button type="submit" class="mf-btn-primary whitespace-nowrap">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" /></svg>
                        Download backup
                    </button>
                </form>
                <p class="mt-2 text-[11.5px]" style="color: var(--faint);">
                    Keep the password safe — a backup can't be restored without it.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th class="mf-th">Table</th>
                            <th class="mf-th text-right">Rows</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rowCounts as $table => $count)
                            <tr style="border-top: 1px solid var(--line-2);">
                                <td class="mf-td">{{ $tableLabels[$table] ?? $table }}</td>
                                <td class="mf-td text-right font-mono" style="color: var(--ink-2);">{{ number_format($count) }}</td>
                            </tr>
                        @endforeach
                        <tr style="border-top: 1px solid var(--line);">
                            <td class="mf-td font-medium">Total</td>
                            <td class="mf-td text-right font-mono font-medium">{{ number_format($totalRows) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Restore (danger zone) --}}
        <div class="mf-panel" style="border-color: var(--danger);">
            <div class="p-4" style="border-bottom: 1px solid var(--line-2);">
                <h2 class="text-[15px] font-medium" style="color: var(--danger);">Restore from backup</h2>
                <div class="mt-1 text-[12.5px]" style="color: var(--muted);">
                    This <strong>replaces all data</strong> with the contents of the uploaded file — anything
                    created since that backup was taken will be lost. The whole restore runs in one transaction,
                    so if anything goes wrong nothing is changed.
                </div>
            </div>

            <form action="{{ route('backup.restore') }}" method="POST" enctype="multipart/form-data" class="p-4"
                  onsubmit="return confirm('Replace ALL data with this backup file? This cannot be undone.');">
                @csrf

                <div class="mb-4">
                    <label for="backup_file" class="mf-label">Backup file (.mfbackup)</label>
                    <input type="file" name="backup_file" id="backup_file" accept=".mfbackup" required
                           class="mf-input">
                </div>

                <div class="mb-4">
                    <label for="restore_password" class="mf-label">Backup password</label>
                    <input type="password" name="password" id="restore_password" required
                           autocomplete="off" placeholder="The password used to create this backup"
                           class="mf-input" style="max-width: 320px;">
                </div>

                <div class="mb-4">
                    <label for="confirm" class="mf-label">Type <span class="font-mono">RESTORE</span> to confirm</label>
                    <input type="text" name="confirm" id="confirm" value="{{ old('confirm') }}" autocomplete="off"
                           placeholder="RESTORE" class="mf-input" style="max-width: 220px;">
                </div>

                <button type="submit" class="mf-btn-primary" style="background: var(--danger); border-color: var(--danger);">
                    Restore &amp; replace all data
                </button>
            </form>
        </div>

        <p class="mt-4 text-[11.5px]" style="color: var(--faint);">
            Backups include database records and product images, and restore on any install (customer
            contact details are re-encrypted automatically). They assume the same schema version — run
            migrations first on a fresh install.
        </p>
    </div>
</x-app-layout>
