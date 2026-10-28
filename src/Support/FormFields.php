<?php

namespace Packstub\CoEdit\Support;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * What a merge needs to know about a form: which top-level fields it edits,
 * what they are called and how a value reads to a person. Shared by edit
 * pages and modal edit actions.
 */
class FormFields
{
    /**
     * Top-level field names: `meta.color` and `meta.size` are both `meta`.
     *
     * @param  list<string>  $ignore
     * @return list<string>
     */
    public static function names(Schema $schema, array $ignore = []): array
    {
        $names = array_map(
            fn (Field $field): string => Str::before($field->getStatePath(isAbsolute: false), '.'),
            array_values($schema->getFlatFields(withHidden: true)),
        );

        return array_values(array_diff(array_unique($names), $ignore));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $ignore
     * @return array<string, mixed>
     */
    public static function only(Schema $schema, array $data, array $ignore = []): array
    {
        return Arr::only($data, self::names($schema, $ignore));
    }

    public static function find(Schema $schema, string $name): ?Field
    {
        foreach ($schema->getFlatFields(withHidden: true) as $field) {
            if ($field->getStatePath(isAbsolute: false) === $name) {
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

        $related = $field->getRelationship()->getRelated();
        $query = $related->newQuery()->whereKey($keys);

        // The keys on your side come from the browser: only name records the
        // field itself would offer, under its relationship query.
        $modify = (fn (): ?Closure => property_exists($this, 'modifyRelationshipQueryUsing') ? $this->modifyRelationshipQueryUsing : null)->call($field);

        if ($modify) {
            $query = $field->evaluate($modify, ['query' => $query, 'search' => null]) ?? $query;
        }

        return $query
            ->get()
            ->mapWithKeys(fn (Model $record): array => [
                (string) $record->getKey() => $field->hasOptionLabelFromRecordUsingCallback()
                    ? $field->getOptionLabelFromRecord($record)
                    : (string) $record->getAttribute($field->getRelationshipTitleAttribute() ?? $related->getKeyName()),
            ])
            ->all();
    }
}
