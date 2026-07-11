<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerSpecialPriceRequest extends FormRequest
{
    public function rules(): array
    {
        $customerId = $this->route('customer')->id;
        $specialPriceId = $this->route('special_price')?->id;

        return [
            'product_variant_id' => [
                'required',
                'exists:product_variants,id',
                Rule::unique('customer_special_prices', 'product_variant_id')
                    ->where('customer_id', $customerId)
                    ->ignore($specialPriceId),
            ],
            'price' => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'product_variant_id.unique' => 'This customer already has a special price for that variant — edit the existing one instead.',
        ];
    }
}
