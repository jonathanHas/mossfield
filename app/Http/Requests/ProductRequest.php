<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Mirror the controller's policy check here so a factory user posting an
     * incomplete form gets the 403 rather than a validation redirect — form
     * requests run before the action body, so the controller's authorize()
     * alone can't make that ordering hold.
     */
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'name')->ignore($productId),
            ],
            'type' => 'required|in:milk,yoghurt,cheese',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:8192',
            'remove_image' => 'sometimes|boolean',
            'maturation_days' => 'nullable|integer|min:1|required_if:type,cheese',
            'shelf_life_days' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'conversion_only' => 'boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'conversion_only' => $this->boolean('conversion_only'),
        ]);
    }
}
