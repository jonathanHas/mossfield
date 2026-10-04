<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Document numbering counter. next() must be called INSIDE the transaction that
 * writes the document: the row is held with lockForUpdate() until commit, so
 * concurrent issues serialise, and a rolled-back issue rolls the counter back
 * with it rather than burning a number.
 */
class DocumentSequence extends Model
{
    protected $fillable = ['key', 'next_value'];

    protected $casts = ['next_value' => 'integer'];

    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    /**
     * Claim the next value for a key. Creates the counter row if missing (a
     * fresh install seeds them, but a new document type shouldn't need a
     * migration just to start counting).
     */
    public static function next(string $key): int
    {
        $row = static::query()->where('key', $key)->lockForUpdate()->first();

        if (! $row) {
            static::query()->insertOrIgnore([
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = static::query()->where('key', $key)->lockForUpdate()->firstOrFail();
        }

        $value = (int) $row->next_value;

        DB::table('document_sequences')
            ->where('id', $row->id)
            ->update(['next_value' => $value + 1, 'updated_at' => now()]);

        return $value;
    }

    /** Format a claimed value: INV-000123. */
    public static function format(string $prefix, int $value): string
    {
        return $prefix.'-'.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
