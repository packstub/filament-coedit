<?php

namespace Packstub\CoEdit\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Merge\ThreeWayMerge;
use UnitEnum;

/**
 * For EditRecord pages: presence in the header, and a save that merges.
 *
 * The form as it was loaded is kept as the merge base. On save the record is
 * read again and filled through the same form; fields only the other person
 * changed keep their value, fields only you changed take yours, and fields
 * you both changed open a dialog to pick per field. Nobody waits on a lock.
 */
trait InteractsWithCoEdit
{
    use HasPresence;

    /** @var array<string, mixed> the form fields as they were loaded or last saved */
    #[Locked]
    public array $coeditBase = [];

    /** @var array<string, mixed> their value of every field in conflict */
    #[Locked]
    public array $coeditTheirs = [];

    /** @var list<string> fields both sides changed, waiting for the dialog */
    #[Locked]
    public array $coeditConflicts = [];

    /** The last save this page has merged, from the presence store. */
    #[Locked]
    public int $coeditVersion = 0;

    /** @var array{0: bool, 1: bool} the arguments of the save the dialog interrupted */
    #[Locked]
    public array $coeditSaveArguments = [true, true];

    public function mountInteractsWithCoEdit(): void
    {
        $this->coeditBase = $this->coeditFields($this->data ?? []);
        $this->coeditVersion = (int) (CoEdit::store()->lastSave($this->getCoEditRoom())['version'] ?? 0);
    }

    public function getCoEditMode(): string
    {
        return 'editing';
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->coeditSaveArguments = [$shouldRedirect, $shouldSendSavedNotification];

        if (! $this->coeditMergeBeforeSave()) {
            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /**
     * Bring the other person's saved changes into the open form without saving.
     * Fields you have changed yourself stay as you left them.
     */
    public function coeditPull(): void
    {
        $theirs = $this->coeditTheirs();

        if ($theirs === null) {
            return;
        }

        $result = ThreeWayMerge::merge($this->coeditBase, $this->coeditFields($this->data), $theirs);

        $this->data = [...$this->data, ...$result->data];
        $this->coeditBase = [...$theirs, ...Arr::only($this->coeditBase, $result->conflicts)];
        $this->coeditAcknowledge(advanceVersion: ! $result->hasConflicts());

        Notification::make()
            ->title(__('filament-coedit::coedit.pull.title'))
            ->body($result->hasConflicts()
                ? __('filament-coedit::coedit.pull.conflicts', ['fields' => $this->coeditLabels($result->conflicts)])
                : ($result->taken === [] ? null : __('filament-coedit::coedit.pull.taken', ['fields' => $this->coeditLabels($result->taken)])))
            ->success()
            ->send();
    }

    public function coeditResolveAction(): Action
    {
        return Action::make('coeditResolve')
            ->modalIcon('heroicon-o-arrows-right-left')
            ->modalIconColor('warning')
            ->modalWidth('2xl')
            ->modalHeading(fn (): string => __('filament-coedit::coedit.conflict.heading', ['name' => $this->coeditTheirName()]))
            ->modalDescription(fn (): string => trans_choice('filament-coedit::coedit.conflict.description', count($this->coeditConflicts), ['name' => $this->coeditTheirName()]))
            ->schema(fn (): array => array_map(
                fn (string $field): Radio => Radio::make("choices.{$field}")
                    ->label($this->coeditLabel($field))
                    ->options([
                        'mine' => __('filament-coedit::coedit.conflict.mine'),
                        'theirs' => __('filament-coedit::coedit.conflict.theirs', ['name' => $this->coeditTheirName()]),
                    ])
                    ->descriptions([
                        'mine' => $this->coeditDisplay($field, $this->data[$field] ?? null),
                        'theirs' => $this->coeditDisplay($field, $this->coeditTheirs[$field] ?? null),
                    ])
                    ->default('mine')
                    ->inline()
                    ->required(),
                $this->coeditConflicts,
            ))
            ->modalSubmitActionLabel(__('filament-coedit::coedit.conflict.submit'))
            ->action(fn (array $data) => $this->coeditResolve($data['choices'] ?? []));
    }

    /**
     * @param  array<string, string>  $choices  field => mine|theirs
     */
    protected function coeditResolve(array $choices): void
    {
        foreach ($this->coeditConflicts as $field) {
            if (($choices[$field] ?? 'mine') === 'theirs') {
                $this->data[$field] = $this->coeditTheirs[$field] ?? null;
            }

            // Settled: from here on their value is the base, so the save only sees your choice.
            $this->coeditBase[$field] = $this->coeditTheirs[$field] ?? null;
        }

        $this->coeditTheirs = [];
        $this->coeditConflicts = [];

        $this->save(...$this->coeditSaveArguments);
    }

    /**
     * Merge the database into the form; false when the dialog has to settle conflicts first.
     */
    protected function coeditMergeBeforeSave(): bool
    {
        try {
            $theirs = $this->coeditTheirs();
        } catch (ModelNotFoundException) {
            Notification::make()
                ->title(__('filament-coedit::coedit.deleted', ['name' => $this->coeditTheirName()]))
                ->danger()
                ->persistent()
                ->send();

            return false;
        }

        if ($theirs === null) {
            return true;
        }

        $result = ThreeWayMerge::merge($this->coeditBase, $this->coeditFields($this->data), $theirs);

        $this->data = [...$this->data, ...$result->data];
        $this->coeditAcknowledge(advanceVersion: false);

        if ($result->taken !== []) {
            Notification::make()
                ->title(__('filament-coedit::coedit.taken.title', ['name' => $this->coeditTheirName()]))
                ->body(__('filament-coedit::coedit.taken.body', ['fields' => $this->coeditLabels($result->taken)]))
                ->info()
                ->send();
        }

        if (! $result->hasConflicts()) {
            return true;
        }

        $this->coeditConflicts = $result->conflicts;
        $this->coeditTheirs = Arr::only($theirs, $result->conflicts);
        $this->coeditBase = [...$this->coeditBase, ...Arr::except($theirs, $result->conflicts)];

        $this->mountAction('coeditResolve');

        return false;
    }

    /**
     * Called by the service provider when Filament reports this page saved the record.
     */
    public function coeditAfterSave(): void
    {
        $data = $this->coeditFields($this->data);
        $changed = ThreeWayMerge::changed($this->coeditBase, $data);

        $this->coeditBase = $data;

        if ($changed === []) {
            return;
        }

        $save = CoEdit::store()->recordSave($this->getCoEditRoom(), CoEdit::who(), array_map($this->coeditLabel(...), $changed));
        $this->coeditVersion = $save['version'];

        $this->dispatch('coedit-acknowledged', version: $save['version'])->to(Presence::class);
        $this->js("window.dispatchEvent(new CustomEvent('filament-coedit-seen'))");
    }

    /**
     * The record as it is in the database now, filled through this form, or
     * null when it matches what the form was loaded from.
     *
     * @return array<string, mixed>|null
     */
    protected function coeditTheirs(): ?array
    {
        $record = $this->getRecord();
        $mine = $this->data;

        $record->refresh();

        $this->form->fill($this->mutateFormDataBeforeFill($record->attributesToArray()));
        $theirs = $this->coeditFields($this->data ?? []);
        $this->data = $mine;

        return ThreeWayMerge::changed($this->coeditBase, $theirs) === [] ? null : $theirs;
    }

    /**
     * Only the values the form edits take part: ids, timestamps and other
     * attributes the form carries along are not something to merge.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coeditFields(array $data): array
    {
        return Arr::only($data, $this->coeditFieldNames());
    }

    /**
     * @return list<string>
     */
    protected function coeditFieldNames(): array
    {
        return array_values(array_unique(array_map(
            fn (Field $field): string => Str::before($field->getStatePath(isAbsolute: false), '.'),
            array_values($this->form->getFlatFields(withHidden: true)),
        )));
    }

    protected function coeditField(string $name): ?Field
    {
        foreach ($this->form->getFlatFields(withHidden: true) as $field) {
            if ($field->getStatePath(isAbsolute: false) === $name) {
                return $field;
            }
        }

        return null;
    }

    protected function coeditLabel(string $name): string
    {
        $label = $this->coeditField($name)?->getLabel();

        return filled($label) ? strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label) : Str::headline($name);
    }

    /**
     * @param  list<string>  $names
     */
    protected function coeditLabels(array $names): string
    {
        return implode(', ', array_map($this->coeditLabel(...), $names));
    }

    /**
     * A value as the person would recognise it: option labels for selects and
     * radios, plain text for rich content, "empty" for nothing.
     */
    protected function coeditDisplay(string $name, mixed $value): string
    {
        $field = $this->coeditField($name);
        $options = $field && method_exists($field, 'getOptions') ? Arr::dot((array) $field->getOptions()) : [];

        $values = collect(Arr::wrap($value))->flatten()
            ->map(fn (mixed $item): mixed => $item instanceof UnitEnum ? ($item->value ?? $item->name) : $item)
            ->filter(fn (mixed $item): bool => filled($item) || is_bool($item))
            ->map(function (mixed $item) use ($options): string {
                if (is_bool($item)) {
                    return $item ? __('filament-coedit::coedit.yes') : __('filament-coedit::coedit.no');
                }

                if (is_scalar($item)) {
                    $option = collect($options)->first(fn (mixed $label, int|string $key): bool => Str::afterLast((string) $key, '.') === (string) $item);

                    return $option !== null ? (string) $option : (string) $item;
                }

                return json_encode($item) ?: '';
            });

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($values->implode(', ')))) ?? '');

        return $text === '' ? __('filament-coedit::coedit.empty') : Str::limit($text, 140);
    }

    protected function coeditTheirName(): string
    {
        $save = CoEdit::store()->lastSave($this->getCoEditRoom());

        return $save !== null && $save['version'] > $this->coeditVersion && $save['user'] !== CoEdit::who()['user']
            ? $save['name']
            : __('filament-coedit::coedit.someone');
    }

    protected function coeditAcknowledge(bool $advanceVersion): void
    {
        $version = (int) (CoEdit::store()->lastSave($this->getCoEditRoom())['version'] ?? 0);

        if ($advanceVersion) {
            $this->coeditVersion = $version;
        }

        $this->dispatch('coedit-acknowledged', version: $version)->to(Presence::class);
        $this->js("window.dispatchEvent(new CustomEvent('filament-coedit-seen'))");
    }
}
