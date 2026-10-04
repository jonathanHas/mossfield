<?php

namespace App\Http\Controllers\Concerns;

use App\Models\OrderItem;

/**
 * Weight rules shared by the two fulfilment surfaces (the office fulfil form
 * on orders/show and the phone picking flow). Finding 5: the per-unit figures
 * are what gets validated and stored; the client's summed total is ignored.
 *
 *  - per-unit variable weight (wheels): `weights[]`, exactly one per unit
 *    picked, each > 0 — the total is recomputed from them.
 *  - bulk-weighed variable weight (vacuum packs): a single `actual_weight_kg`.
 *  - fixed-quantity lines: no weight at all.
 */
trait ValidatesFulfilmentWeights
{
    /** @return array<string, string> extra rules to merge into the request's rules */
    private function fulfilmentWeightRules(OrderItem $orderItem): array
    {
        if (! $orderItem->isVariableWeight()) {
            return [];
        }

        if ($orderItem->isBulkWeighed()) {
            return ['actual_weight_kg' => 'required|numeric|min:0.001'];
        }

        return [
            'weights' => 'required|array|size:'.(int) request('quantity'),
            'weights.*' => 'required|numeric|min:0.001',
        ];
    }

    /** @return array<string, string> */
    private function fulfilmentWeightMessages(): array
    {
        return [
            'weights.required' => 'Weigh each unit — one weight per unit picked.',
            'weights.size' => 'Enter exactly one weight per unit picked (:size).',
            'weights.*.required' => 'Every unit needs a weight.',
            'weights.*.numeric' => 'Each weight must be a number of kilograms.',
            'weights.*.min' => 'Each weight must be more than zero.',
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
