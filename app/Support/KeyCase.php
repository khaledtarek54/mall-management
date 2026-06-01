<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Recursively re-cases array keys. Used by the API boundary middleware to
 * accept camelCase request bodies (the Flutter app's convention) while the
 * backend works in snake_case, and to emit camelCase responses. Kept in one
 * place so request-in and response-out transforms can't drift (DRY).
 */
class KeyCase
{
    /** @param array<mixed> $input */
    public static function camelKeys(array $input): array
    {
        return self::rekey($input, fn (string $key) => Str::camel($key));
    }

    /** @param array<mixed> $input */
    public static function snakeKeys(array $input): array
    {
        return self::rekey($input, fn (string $key) => Str::snake($key));
    }

    /**
     * @param  array<mixed>  $input
     * @param  callable(string):string  $transform
     * @return array<mixed>
     */
    private static function rekey(array $input, callable $transform): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            $newKey = is_string($key) ? $transform($key) : $key;
            $result[$newKey] = is_array($value) ? self::rekey($value, $transform) : $value;
        }

        return $result;
    }
}
