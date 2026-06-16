@php
    /** @var \App\Models\DeliveryRun|null $run */
    $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="name" class="mf-label">Route name</label>
        <input type="text" name="name" id="name" class="mf-input" required
               value="{{ old('name', $run->name ?? '') }}" placeholder="e.g. Dublin">
        @error('name') <div class="mf-error">{{ $message }}</div> @enderror
    </div>
    <div>
        <label for="day_of_week" class="mf-label">Delivery day</label>
        <select name="day_of_week" id="day_of_week" class="mf-select">
            <option value="">Whole week (w/c)</option>
            @foreach ($days as $value => $label)
                <option value="{{ $value }}" {{ (string) old('day_of_week', $run->day_of_week ?? '') === (string) $value ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        @error('day_of_week') <div class="mf-error">{{ $message }}</div> @enderror
    </div>
    <div>
        <label for="driver" class="mf-label">Driver</label>
        <input type="text" name="driver" id="driver" class="mf-input"
               value="{{ old('driver', $run->driver ?? '') }}" placeholder="e.g. Stuart">
        @error('driver') <div class="mf-error">{{ $message }}</div> @enderror
    </div>
    <div>
        <label for="sort_order" class="mf-label">Tab order</label>
        <input type="number" name="sort_order" id="sort_order" class="mf-input" min="0"
               value="{{ old('sort_order', $run->sort_order ?? 0) }}">
        @error('sort_order') <div class="mf-error">{{ $message }}</div> @enderror
    </div>
    <div class="sm:col-span-2">
        <label for="capacity_note" class="mf-label">Capacity note</label>
        <input type="text" name="capacity_note" id="capacity_note" class="mf-input"
               value="{{ old('capacity_note', $run->capacity_note ?? '') }}"
               placeholder="e.g. 80 crates of milk is the max for delivery.">
        @error('capacity_note') <div class="mf-error">{{ $message }}</div> @enderror
    </div>
    <div class="sm:col-span-2">
        <label class="inline-flex items-center gap-2 text-[13px]">
            <input type="checkbox" name="is_active" value="1" class="mf-checkbox"
                   {{ old('is_active', $run->is_active ?? true) ? 'checked' : '' }}>
            Active (shows as a tab on the chilled run sheet)
        </label>
    </div>
</div>
