<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Encrypt legacy plaintext values in customer PII columns so they match
     * the new EncryptedNullable cast on the Customer model.
     *
     * Idempotent: skips rows whose current value already decrypts cleanly.
     * Safe to re-run after partial failure. Always back up the DB first.
     */
    private const FIELDS = ['phone', 'address', 'city', 'postal_code', 'notes'];

    public function up(): void
    {
        DB::table('customers')->orderBy('id')->lazy()->each(function (object $row): void {
            $updates = [];

            foreach (self::FIELDS as $field) {
                $value = $row->{$field} ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                if ($this->isAlreadyEncrypted($value)) {
                    continue;
                }

                $updates[$field] = Crypt::encryptString((string) $value);
            }

            if ($updates !== []) {
                DB::table('customers')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        DB::table('customers')->orderBy('id')->lazy()->each(function (object $row): void {
            $updates = [];

            foreach (self::FIELDS as $field) {
                $value = $row->{$field} ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $updates[$field] = Crypt::decryptString((string) $value);
                } catch (DecryptException) {
                    // Leave values that don't decrypt — they were already plaintext.
                }
            }

            if ($updates !== []) {
                DB::table('customers')->where('id', $row->id)->update($updates);
            }
        });
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
