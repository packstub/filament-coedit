<?php

namespace Packstub\CoEdit\Merge;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Str;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Merges form data field by field, the way git merges lines.
 *
 * `base` is the form as it was loaded, `mine` is the form being saved and
 * `theirs` is the record as it is in the database now, filled through the
 * same form. A field only one side changed takes that side; a field both
 * sides changed to different values is a conflict for the person to settle.
 */
class ThreeWayMerge
{
    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $mine
     * @param  array<string, mixed>  $theirs
     */
    public static function merge(array $base, array $mine, array $theirs): MergeResult
    {
        $merged = $mine;
        $taken = [];
        $conflicts = [];

        foreach (array_unique([...array_keys($base), ...array_keys($theirs)]) as $field) {
            $b = self::normalize($base[$field] ?? null);
            $t = self::normalize($theirs[$field] ?? null);

            if ($t === $b) {
                continue;
            }

            $m = self::normalize($mine[$field] ?? null);

            if ($m === $t) {
                continue;
            }

            if ($m === $b) {
                $merged[$field] = $theirs[$field] ?? null;
                $taken[] = $field;

                continue;
            }

            $conflicts[] = $field;
        }

        return new MergeResult($merged, $taken, $conflicts);
    }

    /**
     * The fields whose value differs between two versions of the form.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public static function changed(array $from, array $to): array
    {
        return array_values(array_filter(
            array_unique([...array_keys($from), ...array_keys($to)]),
            fn (string $field): bool => self::normalize($from[$field] ?? null) !== self::normalize($to[$field] ?? null),
        ));
    }

    /**
     * A comparable fingerprint of a form value.
     *
     * Forms do not keep types stable: an input sends "12" for a column that
     * was filled with 12, a cleared field sends "" where the record had null,
     * and repeaters and file uploads key their items with fresh UUIDs on
     * every fill. None of that is a change.
     */
    public static function normalize(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }

    protected static function canonical(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return self::canonical($value->value);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof JsonSerializable) {
            return self::canonical($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return self::canonical((string) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return self::number($value);
        }

        if (is_string($value)) {
            if ($value === '') {
                return null;
            }

            if (is_numeric($value) && ! preg_match('/^0\d/', $value)) {
                return self::number($value + 0);
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', $value)) {
                return substr(str_replace('T', ' ', $value), 0, 16).(strlen($value) >= 19 ? substr($value, 16, 3) : ':00');
            }

            return $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $keys = array_keys($value);
            $isUuidKeyed = $keys !== [] && array_reduce($keys, fn (bool $carry, int|string $key): bool => $carry && is_string($key) && Str::isUuid($key), true);

            $value = array_map(self::canonical(...), $isUuidKeyed ? array_values($value) : $value);

            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        }

        return $value;
    }

    protected static function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 10, '.', ''), '0'), '.') ?: '0';
    }
}
