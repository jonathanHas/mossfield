<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt on write, decrypt on read — but tolerate pre-migration plaintext
 * values so a deploy does not break customer-page loads before the backfill
 * migration runs. When a legacy plaintext value is read, we log a warning
 * so the missed rows are visible.
 */
class EncryptedNullable implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            Log::warning('encrypted_nullable: legacy plaintext read', [
                'model' => $model::class,
                'key' => $key,
                'id' => $model->getKey(),
            ]);

            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
