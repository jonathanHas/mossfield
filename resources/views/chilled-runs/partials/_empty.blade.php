<div class="mf-panel">
    <div class="p-10 text-center">
        <div class="text-[15px] font-medium mb-1">No delivery runs yet</div>
        <div class="text-[13px] mb-4" style="color: var(--muted);">
            Set up your weekly runs (day, route, driver) and assign customers as stops.
        </div>
        @can('create', App\Models\DeliveryRun::class)
            <a href="{{ route('delivery-runs.index') }}" class="mf-btn-primary">Manage delivery runs</a>
        @endcan
    </div>
</div>
