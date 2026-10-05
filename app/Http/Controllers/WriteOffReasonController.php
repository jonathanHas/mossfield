<?php

namespace App\Http\Controllers;

use App\Models\WriteOffReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The extendable list of write-off reasons (office/admin). A reason in use is
 * deactivated, never deleted — there is no destroy route, so no write-off is
 * ever orphaned (the FK is restrictOnDelete as the backstop).
 */
class WriteOffReasonController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', WriteOffReason::class);

        $reasons = WriteOffReason::ordered()->withCount('writeOffs')->get();

        return view('write-off-reasons.index', compact('reasons'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', WriteOffReason::class);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80', 'unique:write_off_reasons,label'],
        ], [], ['label' => 'reason']);

        // The code is a slug of the label, made unique if it collides.
        $base = Str::limit(Str::slug($validated['label'], '_'), 36, '') ?: 'reason';
        $code = $base;
        for ($n = 2; WriteOffReason::where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        $reason = WriteOffReason::create([
            'code' => $code,
            'label' => $validated['label'],
            'is_active' => true,
            'sort_order' => ((int) WriteOffReason::max('sort_order')) + 10,
        ]);

        return redirect()->route('write-off-reasons.index')
            ->with('success', "Reason \"{$reason->label}\" added.");
    }

    public function update(Request $request, WriteOffReason $writeOffReason): RedirectResponse
    {
        $this->authorize('update', $writeOffReason);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:80', 'unique:write_off_reasons,label,'.$writeOffReason->id],
            'is_active' => ['sometimes', 'boolean'],
        ], [], ['label' => 'reason']);

        $writeOffReason->update($validated);

        $message = array_key_exists('is_active', $validated)
            ? ($writeOffReason->is_active ? "\"{$writeOffReason->label}\" is back in use." : "\"{$writeOffReason->label}\" deactivated — it stays on past write-offs.")
            : "Reason renamed to \"{$writeOffReason->label}\".";

        return redirect()->route('write-off-reasons.index')->with('success', $message);
    }
}
