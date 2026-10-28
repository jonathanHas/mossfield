<?php

namespace App\Http\Requests;

use App\Models\BatchItem;
use App\Models\CheeseCuttingLog;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One wheel → vacuum packs. The wheel is weighed before it is cut, so every
 * other weight on the form has a real, measured ceiling:
 *
 *  - total_weight_kg (the packs) ≤ wheel_weight_kg
 *  - vacuum_packs_created × the pack variant's nominal weight ≤ wheel_weight_kg
 *  - cut_date within [batch production date, today]
 *
 * Authorisation is the route group + CheeseCuttingLog policy, not this class.
 */
class CheeseCutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CheeseCuttingLog::class);
    }

    public function rules(): array
    {
        $production = $this->batchItem()->batch->production_date->toDateString();

        return [
            'cut_date' => "required|date|after_or_equal:{$production}|before_or_equal:today",
            'vacuum_pack_variant_id' => 'required|exists:product_variants,id',
            'wheel_weight_kg' => 'required|numeric|min:0.001',
            'vacuum_packs_created' => 'required|integer|min:1',
            'total_weight_kg' => 'required|numeric|min:0.001|lte:wheel_weight_kg',
            'notes' => 'nullable|string|max:1000',
            'form_token' => 'nullable|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'cut_date.after_or_equal' => 'The cut date cannot be before the batch was produced (:date).',
            'total_weight_kg.lte' => 'The packs cannot weigh more than the wheel they came from.',
        ];
    }

    public function attributes(): array
    {
        return [
            'wheel_weight_kg' => 'wheel weight',
            'total_weight_kg' => 'total pack weight',
            'vacuum_packs_created' => 'vacuum packs created',
            'vacuum_pack_variant_id' => 'vacuum pack type',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['wheel_weight_kg', 'vacuum_packs_created', 'vacuum_pack_variant_id'])) {
                    return;
                }

                $packWeight = (float) (ProductVariant::find($this->input('vacuum_pack_variant_id'))?->weight_kg ?? 0);
                if ($packWeight <= 0) {
                    return; // no nominal pack weight to check against
                }

                $packs = (int) $this->input('vacuum_packs_created');
                $wheel = (float) $this->input('wheel_weight_kg');
                $max = (int) floor($wheel / $packWeight + 1e-9);

                if ($packs > $max) {
                    $validator->errors()->add('vacuum_packs_created', sprintf(
                        '%d packs at %.3f kg each is %.3f kg — more than the %.3f kg wheel. At most %d packs can come off it.',
                        $packs, $packWeight, $packs * $packWeight, $wheel, $max,
                    ));
                }
            },
        ];
    }

    private function batchItem(): BatchItem
    {
        return $this->route('batchItem');
    }
}
