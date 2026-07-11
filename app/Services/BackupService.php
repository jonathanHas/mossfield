<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Logical, database-agnostic backup & restore of the app's business data.
 *
 * Rows are read/written with the raw query builder (DB::table), NOT Eloquent,
 * so the encrypted Customer PII columns (phone/address/city/postal_code/notes)
 * round-trip as ciphertext unchanged and stay valid against the same APP_KEY.
 * Eloquent would decrypt-then-re-encrypt and mangle legacy plaintext rows.
 */
class BackupService
{
    /** Current backup file format version. */
    public const FORMAT_VERSION = 2;

    /**
     * Customer columns encrypted at rest by App\Casts\EncryptedNullable (see the
     * Customer model). They are DECRYPTED into the backup on export and
     * RE-ENCRYPTED with the local APP_KEY on import, so a backup restores cleanly
     * on any install regardless of the key it was taken with.
     *
     * @var list<string>
     */
    public const ENCRYPTED_CUSTOMER_COLUMNS = ['phone', 'address', 'city', 'postal_code', 'notes'];

    /**
     * Backed-up tables in foreign-key (insert) order. Restore deletes in the
     * reverse of this order. `batches` is self-referential (source_batch_id),
     * so its rows are exported/inserted ordered by id — a source batch always
     * predates its mature offspring.
     *
     * Framework/infra tables (migrations, sessions, cache, jobs, …) are
     * deliberately excluded — they are recreated by `php artisan migrate`.
     *
     * @var list<string>
     */
    public const TABLES = [
        'users',
        'products',
        'delivery_runs',
        'product_variants',
        'customers',
        'batches',
        'batch_items',
        'orders',
        'customer_special_prices',
        'order_items',
        'cheese_cutting_logs',
        'cheese_conversion_logs',
        'order_allocations',
    ];

    /**
     * Build the full backup payload: metadata + every table's raw rows.
     *
     * @return array{meta: array<string, mixed>, tables: array<string, list<array<string, mixed>>>}
     */
    public function export(): array
    {
        $tables = [];
        $counts = [];

        foreach (self::TABLES as $table) {
            $query = DB::table($table);

            // Deterministic order; batches must be parents-before-children.
            if (Schema::hasColumn($table, 'id')) {
                $query->orderBy('id');
            }

            $rows = $query->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            if ($table === 'customers') {
                $rows = array_map([$this, 'decryptCustomerPii'], $rows);
            }

            $tables[$table] = $rows;
            $counts[$table] = count($rows);
        }

        return [
            'meta' => [
                'app' => 'mossfield',
                'format_version' => self::FORMAT_VERSION,
                'pii_format' => 'plaintext',
                'generated_at' => now()->toIso8601String(),
                'app_key_fingerprint' => self::appKeyFingerprint(),
                'row_counts' => $counts,
            ],
            'tables' => $tables,
        ];
    }

    /**
     * Full-replace restore: wipe every business table and load the payload
     * exactly, inside one transaction. Any failure rolls back and leaves the
     * database untouched.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int> rows loaded per table (for the summary flash)
     */
    public function import(array $payload): array
    {
        $tables = $this->validatePayload($payload);
        $this->guardAgainstAdminLockout($tables);

        $summary = [];

        DB::transaction(function () use ($tables, &$summary) {
            Schema::withoutForeignKeyConstraints(function () use ($tables, &$summary) {
                // Delete children before parents.
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->delete();
                }

                // Insert parents before children (chunked to stay under the
                // SQLite bound-parameter limit on wide tables).
                foreach (self::TABLES as $table) {
                    $rows = $tables[$table];
                    $summary[$table] = count($rows);

                    foreach (array_chunk($rows, 200) as $chunk) {
                        $chunk = array_map(static fn ($row) => (array) $row, $chunk);

                        if ($table === 'customers') {
                            $chunk = array_map([$this, 'encryptCustomerPii'], $chunk);
                        }

                        DB::table($table)->insert($chunk);
                    }
                }
            });
        });

        return $summary;
    }

    /**
     * Decrypt the encrypted Customer columns into plaintext for the backup file,
     * tolerating pre-migration plaintext exactly like EncryptedNullable::get().
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decryptCustomerPii(array $row): array
    {
        foreach (self::ENCRYPTED_CUSTOMER_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            try {
                $row[$column] = Crypt::decryptString($value);
            } catch (DecryptException) {
                // Legacy plaintext row — already decrypted, leave as-is.
            }
        }

        return $row;
    }

    /**
     * Re-encrypt the plaintext Customer columns with the local APP_KEY before a
     * raw insert, mirroring EncryptedNullable::set().
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encryptCustomerPii(array $row): array
    {
        foreach (self::ENCRYPTED_CUSTOMER_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            $row[$column] = ($value === null || $value === '')
                ? $value
                : Crypt::encryptString((string) $value);
        }

        return $row;
    }

    /**
     * Short, non-reversible fingerprint of the current APP_KEY. Used to warn
     * on restore that a key mismatch will make encrypted Customer contact
     * fields undecryptable. Never store or log the raw key.
     */
    public static function appKeyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 12);
    }

    /**
     * Live row counts for the current database (shown on the backup page).
     *
     * @return array<string, int>
     */
    public function currentRowCounts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<array<string, mixed>>> the validated tables map
     */
    private function validatePayload(array $payload): array
    {
        if (! isset($payload['meta'], $payload['tables']) || ! is_array($payload['tables'])) {
            throw new RuntimeException('The backup file is not in the expected format.');
        }

        $version = $payload['meta']['format_version'] ?? null;
        if ($version !== self::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported backup format version.');
        }

        $tables = [];
        foreach (self::TABLES as $table) {
            if (! isset($payload['tables'][$table]) || ! is_array($payload['tables'][$table])) {
                throw new RuntimeException("The backup file is missing the '{$table}' table.");
            }
            $tables[$table] = $payload['tables'][$table];
        }

        return $tables;
    }

    /**
     * A valid restore must leave at least one active admin, or the operator
     * would lock themselves out. Mirrors User::isLastActiveAdmin()'s intent.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     */
    private function guardAgainstAdminLockout(array $tables): void
    {
        foreach ($tables['users'] as $user) {
            $isAdmin = ($user['role'] ?? null) === 'admin';
            $isActive = ! empty($user['is_active']);

            if ($isAdmin && $isActive) {
                return;
            }
        }

        throw new RuntimeException('This backup contains no active admin user — restoring it would lock everyone out.');
    }
}
