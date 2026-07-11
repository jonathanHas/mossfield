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
            // Opaque encrypted archive; 500 MB ceiling to allow bundled images.
            'backup_file' => ['required', 'file', 'max:512000'],
            'password' => ['required', 'string'],
            'confirm' => ['required', 'in:RESTORE'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.in' => 'Type RESTORE to confirm you want to replace all data.',
            'confirm.required' => 'Type RESTORE to confirm you want to replace all data.',
        ];
    }
}
