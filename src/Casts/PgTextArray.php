<?php

namespace NextDeveloper\Monitoring\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

/**
 * A PostgreSQL text[] column as a PHP array of strings. The module only stores plain tokens in them (event types, severities,
 * uuids), so elements with commas, quotes, braces or backslashes are refused instead of escaped.
 */
class PgTextArray implements CastsAttributes
{
    public function get($model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}' || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return array_values(array_filter(explode(',', trim($value, '{}')), fn ($v) => $v !== ''));
    }

    public function set($model, string $key, mixed $value, array $attributes): string
    {
        $items = array_values(array_unique(array_map('strval', (array) $value)));

        foreach ($items as $item) {
            if ($item === '' || preg_match('/[,"{}\\\\\s]/', $item)) {
                throw new InvalidArgumentException("Not a plain value for {$key}: {$item}");
            }
        }

        return '{'.implode(',', $items).'}';
    }
}
