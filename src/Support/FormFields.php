<?php

namespace Packstub\CoEdit\Support;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Services\RelationshipJoiner;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Packstub\CoEdit\Merge\ThreeWayMerge;
use Throwable;
use UnitEnum;

/**
 * What a merge needs to know about a form: which top-level fields it edits,
 * what they are called and how a value reads to a person. Shared by edit
 * pages and modal edit actions.
 *
 * @internal
 */
class FormFields
{
    /**
     * The fields a merge compares, by path in the form's data: `meta.color` in a
     * section with its own state path is a field of its own, as is `category.name`
     * in a relationship group. The fields inside a repeater or builder item belong
     * to it (`tags`). An ignored path leaves out everything under it too.
     *
     * @param  list<string>  $ignore
     * @return list<string>
     */
    public static function names(Schema $schema, array $ignore = []): array
    {
        $paths = array_unique(array_map(
            fn (Field $field): string => self::path($schema, $field),
            array_values($schema->getFlatFields(withHidden: true)),
        ));

        $all = array_flip($paths);
        $ignored = array_flip($ignore);

        // A path is left out when one of its parents is a field (a repeater item's) or ignored.
        $covered = function (string $path, array $set, bool $orSelf): bool {
            $prefix = '';

            foreach (explode('.', $path) as $i => $segment) {
                $prefix = $i === 0 ? $segment : "{$prefix}.{$segment}";

                if (isset($set[$prefix]) && ($orSelf || $prefix !== $path)) {
                    return true;
                }
            }

            return false;
        };

        return array_values(array_filter(
            $paths,
            fn (string $path): bool => ! $covered($path, $all, orSelf: false) && ! $covered($path, $ignored, orSelf: true),
        ));
    }

    /**
     * A field's key in a schema's state, the dialog's or a modal's fingerprints:
     * a dot there would nest it. The `@` of a translated field's locale goes too.
     */
    public static function key(string $name): string
    {
        // Reversible, so `a.b` and `a_b` never share a key.
        return strtr($name, ['_' => '_u', '.' => '_d', '@' => '_a']);
    }

    /**
     * Each field's value in the form's data, by path.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $ignore
     * @return array<string, mixed>
     */
    public static function only(Schema $schema, array $data, array $ignore = []): array
    {
        $values = [];

        foreach (self::names($schema, $ignore) as $name) {
            if (Arr::has($data, $name)) {
                $values[$name] = Arr::get($data, $name);
            }
        }

        return $values;
    }

    /**
     * A field's path in the form's data: from the form, not from its parent, so a
     * field in a section with its own state path (`meta`) or in a relationship
     * group (`category`) is `meta.color`, not `color`.
     */
    public static function path(Schema $schema, Field $field): string
    {
        $root = $schema->getStatePath();
        $path = $field->getStatePath();

        return filled($root) && str_starts_with($path, $root.'.') ? substr($path, strlen($root) + 1) : $field->getStatePath(isAbsolute: false);
    }

    public static function find(Schema $schema, string $name): ?Field
    {
        foreach ($schema->getFlatFields(withHidden: true) as $field) {
            if (self::path($schema, $field) === $name) {
                return $field;
            }
        }

        return null;
    }

    public static function label(Schema $schema, string $name): string
    {
        $label = self::find($schema, $name)?->getLabel();

        return filled($label) ? strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label) : Str::headline($name);
    }

    /**
     * @param  list<string>  $names
     */
    public static function labels(Schema $schema, array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => self::label($schema, $name), $names));
    }

    /**
     * A value as the person would recognise it: option labels for selects and
     * radios, plain text for rich content, "empty" for nothing.
     */
    public static function display(Schema $schema, string $name, mixed $value): string
    {
        // A rich editor's document reads as its text.
        if (is_array($value) && ($value['type'] ?? null) === 'doc') {
            $value = self::richText($value);
        }

        $field = self::find($schema, $name);
        $options = $field && method_exists($field, 'getOptions') ? Arr::dot((array) $field->getOptions()) : [];

        if ($field instanceof Select && $field->getRelationship() !== null) {
            $options = self::relationshipOptions($field, Arr::wrap($value)) + $options;
        }

        $values = collect(Arr::wrap($value))->flatten()
            ->map(fn (mixed $item): mixed => $item instanceof UnitEnum ? ($item->value ?? $item->name) : $item)
            ->filter(fn (mixed $item): bool => filled($item) || is_bool($item))
            ->map(function (mixed $item) use ($options): string {
                if (is_bool($item)) {
                    return $item ? __('filament-coedit::coedit.yes') : __('filament-coedit::coedit.no');
                }

                if (is_scalar($item)) {
                    $option = collect($options)->first(fn (mixed $label, int|string $key): bool => Str::afterLast((string) $key, '.') === (string) $item);

                    return $option !== null ? (string) ($option instanceof Htmlable ? strip_tags($option->toHtml()) : $option) : (string) $item;
                }

                return json_encode($item) ?: '';
            });

        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($values->implode(', ')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? __('filament-coedit::coedit.empty') : Str::limit($text, 140);
    }

    /**
     * @param  array<mixed>  $node
     */
    protected static function richText(array $node): string
    {
        if (is_string($node['text'] ?? null)) {
            return $node['text'];
        }

        if (($node['type'] ?? null) === 'hardBreak') {
            return ' ';
        }

        if (($node['type'] ?? null) === 'mention') {
            $label = $node['attrs']['label'] ?? $node['attrs']['id'] ?? null;

            return is_scalar($label) ? '@'.$label : '';
        }

        $children = array_values(array_filter((array) ($node['content'] ?? []), is_array(...)));
        $parts = array_map(self::richText(...), $children);

        // Inline nodes run together (a word split by a mark stays one word), blocks get a space.
        $inline = $children !== [] && ! in_array(false, array_map(fn (array $child): bool => in_array($child['type'] ?? 'text', ThreeWayMerge::RICH_INLINE, strict: true), $children), strict: true);

        return implode($inline ? '' : ' ', array_filter($parts, fn (string $part): bool => trim($part) !== '' || $inline));
    }

    /**
     * Labels of related records by key, for a relationship select whose
     * options are searched rather than preloaded.
     *
     * @param  array<mixed>  $keys
     * @return array<string, string>
     */
    protected static function relationshipOptions(Select $field, array $keys): array
    {
        $keys = array_values(array_filter($keys, fn (mixed $key): bool => is_scalar($key) && $key !== ''));

        if ($keys === []) {
            return [];
        }

        // Built the way Filament builds it (pivot joins and relationship
        // constraints included), then narrowed by the field's own query: the
        // keys on your side come from the browser, so only records the field
        // itself would offer are named.
        try {
            $relationship = Relation::noConstraints(fn (): Relation => $field->getRelationship());
            $keyName = $field->getQualifiedRelatedKeyNameForRelationship($relationship);
            $query = app(RelationshipJoiner::class)->prepareQueryForNoConstraints($relationship)->whereIn($keyName, $keys);

            $modify = (fn (): ?Closure => property_exists($this, 'modifyRelationshipQueryUsing') ? $this->modifyRelationshipQueryUsing : null)->call($field);

            if ($modify) {
                $query = $field->evaluate($modify, ['query' => $query, 'search' => null]) ?? $query;
            }

            return $query
                ->get()
                ->mapWithKeys(fn (Model $record): array => [
                    (string) $record->getAttribute(Str::afterLast($keyName, '.')) => $field->hasOptionLabelFromRecordUsingCallback()
                        ? $field->getOptionLabelFromRecord($record)
                        : (string) data_get($record, str_replace('->', '.', $field->getRelationshipTitleAttribute() ?? $record->getKeyName())),
                ])
                ->all();
        } catch (Throwable) {
            // A label is a nicety: never let it stop the dialog. The keys show instead.
            return [];
        }
    }
}
