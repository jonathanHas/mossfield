<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OrderItem;
use Illuminate\Http\Request;

/**
 * Weight rules shared by the two fulfilment surfaces (the office fulfil form
 * on orders/show and the phone picking flow). Finding 5: the per-unit figures
 * are what gets validated and stored; the client's summed total is ignored.
 *
 *  - per-unit variable weight (wheels): `weights[]`, exactly one per unit
 *    picked, each > 0 — the total is recomputed from them.
 *  - bulk-weighed variable weight (vacuum packs): a single `actual_weight_kg`.
 *  - fixed-quantity lines: no weight at all.
 *
 * E2E finding F-29: "5,210" typed into a number input reached the server as
 * 5210 and would have billed a 5-tonne wheel. Commas are normalised to dots
 * before validation, and when the variant has a nominal weight each unit is
 * bounded at 3 × nominal (bulk lines: 3 × nominal × quantity).
 */
trait ValidatesFulfilmentWeights
{
    /** How far above nominal a weighed unit may be before it's refused. */
    private const WEIGHT_BOUND_FACTOR = 3;

    /**
     * Turn decimal commas into dots in the posted weights, so "5,210" from any
     * client validates as 5.210. Call before validate().
     */
    private function normaliseWeightInput(Request $request): void
    {
        $dot = fn ($value) => is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        if (is_array($request->input('weights'))) {
            $request->merge(['weights' => array_map($dot, $request->input('weights'))]);
        }

        if ($request->has('actual_weight_kg')) {
            $request->merge(['actual_weight_kg' => $dot($request->input('actual_weight_kg'))]);
        }
    }

    private function nominalUnitWeight(OrderItem $orderItem): ?float
    {
        $nominal = (float) ($orderItem->productVariant->weight_kg ?? 0);

        return $nominal > 0 ? $nominal : null;
    }

    /** @return array<string, string> extra rules to merge into the request's rules */
    private function fulfilmentWeightRules(OrderItem $orderItem): array
    {
        if (! $orderItem->isVariableWeight()) {
            return [];
        }

        $nominal = $this->nominalUnitWeight($orderItem);

        if ($orderItem->isBulkWeighed()) {
            $rule = 'required|numeric|min:0.001';
            if ($nominal) {
                $rule .= '|max:'.round(self::WEIGHT_BOUND_FACTOR * $nominal * max(1, (int) request('quantity')), 3);
            }

            return ['actual_weight_kg' => $rule];
        }

        $unitRule = 'required|numeric|min:0.001';
        if ($nominal) {
            $unitRule .= '|max:'.round(self::WEIGHT_BOUND_FACTOR * $nominal, 3);
        }

        return [
            'weights' => 'required|array|size:'.(int) request('quantity'),
            'weights.*' => $unitRule,
        ];
    }

    /** @return array<string, string> */
    private function fulfilmentWeightMessages(?OrderItem $orderItem = null): array
    {
        $nominal = $orderItem ? $this->nominalUnitWeight($orderItem) : null;
        $usual = $nominal ? number_format($nominal, 3) : null;

        return [
            'weights.required' => 'Weigh each unit — one weight per unit picked.',
            'weights.size' => 'Enter exactly one weight per unit picked (:size).',
            'weights.*.required' => 'Every unit needs a weight.',
            'weights.*.numeric' => 'Each weight must be a number of kilograms.',
            'weights.*.min' => 'Each weight must be more than zero.',
            'weights.*.max' => 'Each unit must weigh no more than :max kg — a wheel is usually about '.$usual.' kg. Check the decimal point.',
            'actual_weight_kg.max' => 'The total must be no more than :max kg for this many units — about '.$usual.' kg each is usual. Check the decimal point.',
        ];
    }

    /**
     * Resolve [total kg, per-unit kg list] from the validated input.
     * Per-unit lines derive the total from the list; bulk lines carry only the
     * total; fixed lines carry neither.
     *
     * @return array{?float, ?list<float>}
     */
    private function resolveFulfilmentWeight(array $validated, OrderItem $orderItem): array
    {
        if (isset($validated['weights'])) {
            $units = array_values(array_map(fn ($w) => round((float) $w, 3), $validated['weights']));

            return [round(array_sum($units), 3), $units];
        }

        return [isset($validated['actual_weight_kg']) ? (float) $validated['actual_weight_kg'] : null, null];
    }
}
