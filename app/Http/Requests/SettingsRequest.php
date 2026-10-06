<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The /settings form: the three batch ceilings, or reset=1 to drop back to
 * the config defaults. The route group is admin-only already; the policy
 * check here keeps a refused user from seeing a validation redirect first.
 */
class SettingsRequest extends FormRequest
{
    public const CEILING_TYPES = ['milk', 'yoghurt', 'cheese'];

    public function authorize(): bool
    {
        return $this->user()->can('update', Setting::class);
    }

    public function rules(): array
    {
        if ($this->boolean('reset')) {
            return ['reset' => 'boolean'];
        }

        return collect(self::CEILING_TYPES)
            ->mapWithKeys(fn ($type) => ["batch_ceilings.$type" => 'required|integer|min:1|max:1000000'])
            ->all();
    }

    public function attributes(): array
    {
        return collect(self::CEILING_TYPES)
            ->mapWithKeys(fn ($type) => ["batch_ceilings.$type" => ucfirst($type).' ceiling'])
            ->all();
    }
}
