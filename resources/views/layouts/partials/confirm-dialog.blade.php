{{-- Shared confirmation dialog. Opened by resources/js/confirm.js for any
     form[data-confirm] / button[data-confirm]; replaces the browser's native confirm dialog. --}}
<x-modal name="confirm-action" maxWidth="sm">
    <div class="p-5">
        <h3 id="confirm-action-title" class="text-[15px] font-medium" style="color: var(--ink);">Are you sure?</h3>
        <p id="confirm-action-message" class="mt-1 text-[13px]" style="color: var(--muted);"></p>
        <div class="mt-4 flex items-center justify-end gap-2">
            <button type="button" id="confirm-action-cancel" class="mf-btn-secondary" x-on:click="$dispatch('close')">Cancel</button>
            <button type="button" id="confirm-action-ok" class="mf-btn-primary">Confirm</button>
        </div>
    </div>
</x-modal>
