<?php

namespace App\Site\Sources;

use InvalidArgumentException;
use JsonException;

/** Shared parsing for the two JSON-backed sources. */
final class JsonFile
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException on malformed JSON
     */
    public static function read(string $path, string $key): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Site data file not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        $entries = $data[$key] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new InvalidArgumentException(basename($path).": top-level '{$key}' must be a list");
        }
        foreach ($entries as $i => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException(basename($path)."[{$i}]: entry must be an object");
            }
        }

        return array_values($entries);
    }

    public static function optionalString(array $entry, string $key, string $where): ?string
    {
        $value = $entry[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$where}: '{$key}' must be a string or null");
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
