<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin-set value that overrides a config default (/settings).
 *
 * The static API is the only way in: get() falls back to the caller's
 * default (normally the config value), so a fresh install with no rows
 * behaves exactly as the config says. All rows are read once per request
 * and cached; set()/forget() clear the cache.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = [
        'value' => 'json',
    ];

    /**
     * Rows keyed by `key`, for the current request. Tied to the app instance
     * so a new application (each test, each request under a long-lived
     * worker) starts from the database again.
     *
     * @var array{app: ?object, rows: ?\Illuminate\Support\Collection}
     */
    private static array $cache = ['app' => null, 'rows' => null];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return \Illuminate\Support\Collection<string, self> */
    public static function rows(): \Illuminate\Support\Collection
    {
        if (self::$cache['app'] !== app() || self::$cache['rows'] === null) {
            self::$cache = ['app' => app(), 'rows' => static::query()->get()->keyBy('key')];
        }

        return self::$cache['rows'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::rows()->get($key);

        return $row !== null ? $row->value : $default;
    }

    public static function set(string $key, mixed $value, ?User $by = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
        static::flushCache();
    }

    public static function forget(string $key): void
    {
        static::query()->whereKey($key)->delete();
        static::flushCache();
    }

    public static function flushCache(): void
    {
        self::$cache = ['app' => null, 'rows' => null];
    }

    /** The batch sanity ceiling for a product type: the stored value, else config. */
    public static function batchCeiling(string $type): int
    {
        return (int) static::get("batch_ceilings.$type", config("mossfield.batch_ceilings.$type", 0));
    }
}
