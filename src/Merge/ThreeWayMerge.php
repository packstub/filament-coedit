<?php

namespace Packstub\CoEdit\Merge;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use JsonSerializable;
use Stringable;
use Throwable;
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
            // Base and theirs both come from the database through the same form, so
            // they compare exactly: "1.1" to "1.10" in a text field is their change.
            if (self::normalize($theirs[$field] ?? null, exact: true) === self::normalize($base[$field] ?? null, exact: true)) {
                continue;
            }

            if (self::untouched($base[$field] ?? null, $mine[$field] ?? null)) {
                $merged[$field] = $theirs[$field] ?? null;
                $taken[] = $field;

                continue;
            }

            if (self::normalize($mine[$field] ?? null) !== self::normalize($theirs[$field] ?? null)) {
                $conflicts[] = $field;
            }
        }

        return new MergeResult($merged, $taken, $conflicts);
    }

    /**
     * Whether the form still holds the base. Yours comes from the browser, so it
     * compares through the form noise, except text: an input sends what was typed,
     * so retyping "1.1" as "1.10" is your change, while "12" in an input the record
     * filled with 12 is not. A repeater, builder or key-value field compares exactly
     * too, leaf by leaf: an item you leave alone keeps its types through the browser,
     * and one you retype is text.
     */
    public static function untouched(mixed $base, mixed $mine): bool
    {
        $exact = self::typed($mine);

        return self::normalize($base, $exact) === self::normalize($mine, $exact);
    }

    /** Whether a value from the browser compares exactly (see untouched()). */
    public static function typed(mixed $mine): bool
    {
        return is_string($mine) || is_array($mine);
    }

    /**
     * The fields whose value differs between two versions of the form; `exact`
     * for two versions read from the database (see normalize()).
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public static function changed(array $from, array $to, bool $exact = false): array
    {
        return array_values(array_filter(
            array_unique([...array_keys($from), ...array_keys($to)]),
            fn (string $field): bool => self::normalize($from[$field] ?? null, $exact) !== self::normalize($to[$field] ?? null, $exact),
        ));
    }

    /**
     * A comparable fingerprint of a form value.
     *
     * Forms do not keep types stable: an input sends "12" for a column that
     * was filled with 12, a cleared field sends "" where the record had null,
     * and repeaters and file uploads key their items with fresh UUIDs on
     * every fill. None of that is a change.
     *
     * `exact` keeps text that looks like a number as text ("1.1" is not "1.10"),
     * for two values that come the same way, both read from the database.
     */
    public static function normalize(mixed $value, bool $exact = false): string
    {
        $canonical = self::canonical($value, $exact);

        // Invalid UTF-8 cannot be JSON: compare it byte for byte instead of as "".
        return json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: serialize($canonical);
    }

    protected static function canonical(mixed $value, bool $exact = false): mixed
    {
        if ($value instanceof BackedEnum) {
            return self::canonical($value->value, $exact);
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return self::dateTime(CarbonImmutable::instance($value));
        }

        if ($value instanceof JsonSerializable) {
            return self::canonical($value->jsonSerialize(), $exact);
        }

        if ($value instanceof Stringable) {
            return self::canonical((string) $value, $exact);
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

            // Plain decimals only ("12", "10.00", "-3.5", ".5"), compared digit by digit: "+12",
            // "1e3", "12 " and leading zeros stay text, and long ids never pass through a float.
            if (! $exact && preg_match('/^-?((0|[1-9]\d*)(\.\d+)?|\.\d+)$/', $value)) {
                return self::decimal($value);
            }

            // A whole date-time, with or without the T, seconds, fraction and zone
            // (as Laravel serialises a cast); a text that merely starts with one is text.
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value, $match)) {
                $fraction = rtrim(substr($match[4] ?? '', 1), '0');
                $local = $match[1].' '.$match[2].(($match[3] ?? '') !== '' ? $match[3] : ':00').($fraction !== '' ? '.'.$fraction : '');

                // A zone moves it into the app's time, which a form's own value is in.
                if (($match[5] ?? '') === '') {
                    return $local;
                }

                // Digits that make no date-time ("2026-13-01T10:30Z") stay text.
                try {
                    return self::dateTime(CarbonImmutable::parse($value));
                } catch (Throwable) {
                    return $value;
                }
            }

            return $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            if (($value['type'] ?? null) === 'doc') {
                $value = self::richDocument($value);
            }

            $keys = array_keys($value);
            $isUuidKeyed = $keys !== [] && array_reduce($keys, fn (bool $carry, int|string $key): bool => $carry && is_string($key) && Str::isUuid($key), true);

            $value = array_map(fn (mixed $item): mixed => self::canonical($item, $exact), $isUuidKeyed ? array_values($value) : $value);

            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        }

        return $value;
    }

    /** In the app's time zone, seconds always, a fraction only when there is one. */
    protected static function dateTime(CarbonImmutable $value): string
    {
        $value = $value->setTimezone((string) config('app.timezone', 'UTC'));
        $fraction = rtrim($value->format('u'), '0');

        return $value->format('Y-m-d H:i:s').($fraction !== '' ? '.'.$fraction : '');
    }

    /**
     * Inline nodes of Filament's rich editor; any other node, a custom extension's
     * included, is a block (embeds are far more common than custom inline nodes).
     */
    public const RICH_INLINE = ['text', 'hardBreak', 'image', 'mention', 'mergeTag'];

    /**
     * A rich editor document as Filament's editor writes it back once it loads
     * (`normalize-list-item-content.js`): inline content of a list item goes into
     * a paragraph, and a list item starts with one. Opening the page is no change.
     *
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    protected static function richDocument(array $node): array
    {
        if (! is_array($node['content'] ?? null) && ($node['type'] ?? null) !== 'listItem') {
            return $node;
        }

        $content = array_map(
            fn (mixed $child): mixed => is_array($child) ? self::richDocument($child) : $child,
            array_values((array) ($node['content'] ?? [])),
        );

        if (($node['type'] ?? null) === 'listItem') {
            $normalized = [];
            $paragraph = null;

            foreach ($content as $child) {
                if (! is_array($child) || ! in_array($child['type'] ?? 'text', self::RICH_INLINE, strict: true)) {
                    $normalized[] = $child;
                    $paragraph = null;

                    continue;
                }

                if ($paragraph === null) {
                    $normalized[] = ['type' => 'paragraph', 'content' => []];
                    $paragraph = array_key_last($normalized);
                }

                $normalized[$paragraph]['content'][] = $child;
            }

            if (($normalized[0]['type'] ?? null) !== 'paragraph') {
                array_unshift($normalized, ['type' => 'paragraph', 'content' => []]);
            }

            $content = $normalized;
        }

        return [...$node, 'content' => $content];
    }

    protected static function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : self::decimal(number_format($value, 10, '.', ''));
    }

    /**
     * "10.50" → "10.5", "10.00" → "10", ".5" → "0.5", "-0.0" → "0". Like a float,
     * a fraction counts to 10 places.
     */
    protected static function decimal(string $value): string
    {
        $value = preg_replace('/^(-?)\./', '${1}0.', $value) ?? $value;

        if (strlen(Str::after($value, '.')) > 10 && str_contains($value, '.')) {
            $value = number_format((float) $value, 10, '.', '');
        }

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' || $value === '' ? '0' : $value;
    }
}
