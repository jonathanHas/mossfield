<?php

namespace App\Http\Requests;

use App\Models\BatchItem;
use App\Models\StockWriteOff;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockWriteOffRequest extends FormRequest
{
    /** The policy check lives here: form requests run before the controller body. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', StockWriteOff::class);
    }

    public function rules(): array
    {
        $batch = $this->route('batch');

        return [
            'batch_item_id' => [
                'required', 'integer', 'exists:batch_items,id',
                function (string $attribute, mixed $value, Closure $fail) use ($batch) {
                    if (BatchItem::whereKey($value)->value('batch_id') !== $batch?->id) {
                        $fail('That item belongs to another batch.');
                    }
                },
            ],
            'write_off_reason_id' => [
                'required', 'integer',
                Rule::exists('write_off_reasons', 'id')->where('is_active', true),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.001'],
            'note' => ['nullable', 'string', 'max:255'],
            'written_off_on' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'write_off_reason_id.exists' => 'Choose a reason that is still in use.',
        ];
    }

    public function attributes(): array
    {
        return [
            'batch_item_id' => 'item',
            'write_off_reason_id' => 'reason',
            'weight_kg' => 'weight',
            'written_off_on' => 'date',
        ];
    }
}
