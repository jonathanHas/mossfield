<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductVariantRequest extends FormRequest
{
    public function rules(): array
    {
        $productId = $this->route('product')->id;
        $variantId = $this->route('variant')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_variants', 'name')
                    ->where('product_id', $productId)
                    ->ignore($variantId),
            ],
            'size' => 'required|string|max:255',
            'unit' => 'required|string|max:255',
            'weight_kg' => 'nullable|numeric|min:0',
            'base_price' => 'required|numeric|min:0.01',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:8192',
            'remove_image' => 'sometimes|boolean',
            'is_active' => 'boolean',
            'is_variable_weight' => 'boolean',
            'is_priced_by_weight' => 'boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes('weight_kg', 'required|numeric|min:0.001', function ($input) {
            return ! $input->is_variable_weight || $input->is_priced_by_weight;
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'is_variable_weight' => $this->boolean('is_variable_weight'),
            'is_priced_by_weight' => $this->boolean('is_priced_by_weight'),
        ]);
    }
}
