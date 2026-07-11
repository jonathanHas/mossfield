<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // BackupController already applies role:admin middleware, but we
        // double-check here so this request is safe if reused elsewhere.
        return $this->user()?->isAdmin() === true && $this->user()->is_active;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 50 MB ceiling; plain-text JSON often uploads as text/plain.
            'backup_file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:51200'],
            'confirm' => ['required', 'in:RESTORE'],
            'acknowledge_key_mismatch' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.in' => 'Type RESTORE to confirm you want to replace all data.',
            'confirm.required' => 'Type RESTORE to confirm you want to replace all data.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'acknowledge_key_mismatch' => $this->boolean('acknowledge_key_mismatch'),
        ]);
    }
}
