<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admin-tunable settings (/settings). This cycle exposes the batch sanity
 * ceilings only; config/mossfield.php holds their defaults and a stored
 * value overrides one (Setting::batchCeiling() is the read site).
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Setting::class);

        $stored = Setting::with('updatedBy')
            ->whereIn('key', $this->ceilingKeys())
            ->get()
            ->keyBy('key');

        $ceilings = collect(SettingsRequest::CEILING_TYPES)->mapWithKeys(fn ($type) => [$type => [
            'value' => Setting::batchCeiling($type),
            'default' => (int) config("mossfield.batch_ceilings.$type", 0),
            'stored' => $stored->get("batch_ceilings.$type"),
        ]]);

        return view('settings.index', compact('ceilings'));
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        if ($request->boolean('reset')) {
            $had = Setting::query()->whereIn('key', $this->ceilingKeys())->count();
            foreach ($this->ceilingKeys() as $key) {
                Setting::forget($key);
            }

            return redirect()->route('settings.index')->with('success', $had > 0
                ? 'Batch ceilings reset to the defaults.'
                : 'Batch ceilings are already the defaults.');
        }

        $changes = [];
        foreach (SettingsRequest::CEILING_TYPES as $type) {
            $old = Setting::batchCeiling($type);
            $new = (int) $request->validated("batch_ceilings.$type");
            if ($new !== $old) {
                Setting::set("batch_ceilings.$type", $new, $request->user());
                $changes[] = "$type $old → $new";
            }
        }

        return redirect()->route('settings.index')->with('success', $changes
            ? 'Batch ceilings saved — '.implode(', ', $changes).'.'
            : 'No changes.');
    }

    /** @return list<string> */
    private function ceilingKeys(): array
    {
        return array_map(fn ($type) => "batch_ceilings.$type", SettingsRequest::CEILING_TYPES);
    }
}
