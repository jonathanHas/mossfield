<x-app-layout>
    <x-slot name="header">Delivery Runs</x-slot>

    <div class="px-6 py-5 max-w-2xl">
        <h1 class="text-[22px] font-display font-medium mb-4" style="letter-spacing: -0.4px;">New delivery run</h1>

        <div class="mf-panel">
            <form method="POST" action="{{ route('delivery-runs.store') }}" class="p-4">
                @csrf
                @include('delivery-runs.partials._form', ['run' => null])
                <div class="flex items-center gap-2 mt-5">
                    <button type="submit" class="mf-btn-primary">Create run</button>
                    <a href="{{ route('delivery-runs.index') }}" class="mf-btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
