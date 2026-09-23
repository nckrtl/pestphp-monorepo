<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use UnexpectedValueException;

final class BinaryJson
{
    private const string PREFIX = "\0base64:";

    public static function encode(mixed $value): string|false
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($json !== false || json_last_error() !== JSON_ERROR_UTF8 || ! is_array($value)) {
            return $json;
        }

        $value = self::convert($value, true);
        $value['binary_encoding'] = 'base64-v1';

        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $json): mixed
    {
        $value = json_decode($json, true);

        if (! is_array($value) || ($value['binary_encoding'] ?? null) !== 'base64-v1') {
            return $value;
        }

        unset($value['binary_encoding']);

        try {
            return self::convert($value, false);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    private static function convert(mixed $value, bool $encode): mixed
    {
        if (is_string($value)) {
            return self::convertString($value, $encode);
        }

        if (! is_array($value)) {
            return $value;
        }

        $converted = [];

        foreach ($value as $key => $item) {
            $key = is_string($key) ? self::convertString($key, $encode) : $key;
            $converted[$key] = self::convert($item, $encode);
        }

        return $converted;
    }

    private static function convertString(string $value, bool $encode): string
    {
        if ($encode) {
            return preg_match('//u', $value) !== 1 || str_starts_with($value, self::PREFIX)
                ? self::PREFIX.base64_encode($value)
                : $value;
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $decoded = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($decoded === false) {
            throw new UnexpectedValueException('Invalid binary TIA string.');
        }

        return $decoded;
    }
}
