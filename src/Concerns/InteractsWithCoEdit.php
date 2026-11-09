<?php

namespace Packstub\CoEdit\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Events\ChangesMerged;
use Packstub\CoEdit\Events\ConflictDetected;
use Packstub\CoEdit\Events\ConflictResolved;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Merge\ThreeWayMerge;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Support\FormFields;
use ReflectionObject;
use ReflectionProperty;

/**
 * For EditRecord pages: presence in the header, and a save that merges.
 *
 * The form as it was loaded is kept as the merge base. On save the record is
 * read again and filled through the same form; fields only the other person
 * changed keep their value, fields only you changed take yours, and fields
 * you both changed open a dialog to pick per field. Nobody waits on a lock.
 *
 * A page that overrides save() itself calls coeditMergeBeforeSave() first and
 * stops when it returns false, then coeditAfterSave() once it has saved, ideally
 * all inside CoEdit::store()->whileSaving().
 */
trait InteractsWithCoEdit
{
    use HasPresence;

    /** @var array<string, mixed> the form fields as they were loaded or last saved */
    #[Locked]
    public array $coeditBase = [];

    /**
     * Fields your last save stored differently from how the form holds them
     * (trimmed, hashed, slugged): a hash of the form's value, so while the form
     * still holds it the field counts as untouched by you.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $coeditSaved = [];

    /** @var array<string, mixed> their value of every field in conflict */
    #[Locked]
    public array $coeditTheirs = [];

    /**
     * Their value of conflicting fields in another locale, as the translatable plugin
     * keeps it aside (`$otherLocaleData`): what "Use theirs" writes back there.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $coeditTheirsAside = [];

    /**
     * The other locales of their version as the plugin keeps them aside, from the
     * last fill of it in this request.
     *
     * @var array<string, mixed> `attribute@locale` => value
     */
    protected array $coeditAside = [];

    /** The active locale when the conflicts were found, on a translatable page. */
    #[Locked]
    public ?string $coeditConflictLocale = null;

    /** @var list<string> fields both sides changed, waiting for the dialog */
    #[Locked]
    public array $coeditConflicts = [];

    /** The last save this page has merged, from the presence store. */
    #[Locked]
    public int $coeditVersion = 0;

    /** @var array{0: bool, 1: bool} the arguments of the save the dialog interrupted */
    #[Locked]
    public array $coeditSaveArguments = [true, true];

    /** True while the dialog's own submit is saving, for this request only. */
    protected bool $coeditResolving = false;

    /**
     * True once this request's save has merged, until Filament reports it saved.
     * Filament also reports saves of header actions (a CreateAction) for this
     * page; those must not move the merge base onto unsaved edits.
     */
    protected bool $coeditMerged = false;

    /**
     * Fields merges took from the others since the last committed save, and whose
     * they were: told once a save is committed, also when the first save after the
     * merge failed or rolled back.
     *
     * @var array<string, string> field => their name
     */
    #[Locked]
    public array $coeditTaken = [];

    /**
     * What the dialog settled, told once a save with it is committed.
     *
     * @var array<string, 'mine'|'theirs'>
     */
    #[Locked]
    public array $coeditSettled = [];

    /** Set once the page has mounted, so a mount hook called again from the browser does nothing. */
    #[Locked]
    public bool $coeditMounted = false;

    public function mountInteractsWithCoEdit(): void
    {
        if ($this->coeditMounted) {
            return;
        }

        $this->coeditMounted = true;
        $this->coeditBase = $this->coeditFields();
        $this->coeditVersion = (int) (CoEdit::store()->lastSave($this->getCoEditRoom())['version'] ?? 0);
    }

    protected function getCoEditMode(): string
    {
        return 'editing';
    }

    /**
     * Fields left out of the merge: the form's value is saved as in plain
     * Filament, over anyone's newer one. For fields whose value on this form
     * should always win (derived from other fields on save); a field a job
     * owns is safer left in the merge, or off the form.
     *
     * @return list<string>
     */
    protected function getCoEditIgnoredFields(): array
    {
        return [];
    }

    /**
     * Attributes translated on this page with Filament's spatie-translatable plugin.
     * Each locale of one is a field of its own (`title@ro`), whichever locale the
     * form shows: the others wait in `$otherLocaleData` and are saved too.
     *
     * @return list<string>
     */
    protected function getCoEditTranslatableAttributes(): array
    {
        if (! property_exists($this, 'otherLocaleData') || ! property_exists($this, 'activeLocale') || ! method_exists(static::getResource(), 'getTranslatableAttributes')) {
            return [];
        }

        return array_values(static::getResource()::getTranslatableAttributes());
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->coeditSaveArguments = [$shouldRedirect, $shouldSendSavedNotification];

        try {
            // One save per record at a time: two people pressing Save at once
            // would otherwise both merge against the version before the other's.
            CoEdit::store()->whileSaving($this->getCoEditRoom(), function () use ($shouldRedirect, $shouldSendSavedNotification): void {
                if (! $this->coeditMergeBeforeSave()) {
                    return;
                }

                parent::save($shouldRedirect, $shouldSendSavedNotification);
            });
        } catch (LockTimeoutException) {
            Notification::make()
                ->title(__('filament-coedit::coedit.busy'))
                ->warning()
                ->send();
        } finally {
            $this->coeditMerged = false;
        }
    }

    /**
     * "Get their changes" on the save notice. The notice is a Filament notification,
     * whose buttons reach every component on the page as an event: only the page
     * whose record it is about pulls.
     */
    #[On('coedit-pull')]
    public function coeditPullRequested(mixed $room = null): void
    {
        if ($room === PresenceStore::opaque($this->getCoEditRoom())) {
            $this->coeditPull();
        }
    }

    /**
     * Bring the other person's saved changes into the open form without saving.
     * Fields you have changed yourself stay as you left them.
     */
    public function coeditPull(): void
    {
        // Callable from the browser: as Filament's save() does, ask the resource again
        // whether this person may still edit the record before giving them its current version.
        if (method_exists($this, 'authorizeAccess')) {
            $this->authorizeAccess();
        }

        $this->coeditCheckLocale();

        try {
            $theirs = $this->coeditTheirs();
        } catch (ModelNotFoundException) {
            $this->coeditNotifyDeleted();

            return;
        }

        if ($theirs === null) {
            $this->coeditAcknowledge(advanceVersion: true);

            return;
        }

        $result = ThreeWayMerge::merge($this->coeditBase, $this->coeditMine(), $theirs);

        $this->coeditWrite(Arr::only($result->data, $result->taken), $this->coeditAside);
        $this->coeditRebase([...$theirs, ...Arr::only($this->coeditBase, $result->conflicts)]);
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
            ->schema(function (): array {
                $mine = $this->coeditFields();

                return array_map(fn (string $field): Radio => Radio::make('choices.'.FormFields::key($field))
                    ->label($this->coeditLabel($field))
                    ->options([
                        'mine' => __('filament-coedit::coedit.conflict.mine'),
                        'theirs' => __('filament-coedit::coedit.conflict.theirs', ['name' => $this->coeditTheirName()]),
                    ])
                    ->descriptions([
                        'mine' => FormFields::display($this->form, $this->coeditPathOf($field)[0], $mine[$field] ?? null),
                        'theirs' => FormFields::display($this->form, $this->coeditPathOf($field)[0], $this->coeditTheirs[$field] ?? null),
                    ])
                    ->default('mine')
                    ->inline()
                    ->required(),
                    $this->coeditConflicts,
                );
            })
            ->modalSubmitActionLabel(__('filament-coedit::coedit.conflict.submit'))
            ->action(fn (array $data) => $this->coeditResolve($data['choices'] ?? []));
    }

    /**
     * @param  array<string, string>  $choices  field => mine|theirs
     */
    protected function coeditResolve(array $choices): void
    {
        // The dialog can be mounted from the browser at any time; without conflicts it has nothing to settle.
        if ($this->coeditConflicts === []) {
            return;
        }

        // Their values wait in the shape of the locale the conflict was found in; after a
        // switch (only possible from the browser) the next save merges again instead.
        if ($this->coeditConflictLocale !== $this->coeditActiveLocale()) {
            $this->coeditTheirs = $this->coeditTheirsAside = $this->coeditConflicts = [];

            return;
        }

        $settled = [];

        foreach ($this->coeditConflicts as $field) {
            $settled[$field] = ($choices[FormFields::key($field)] ?? 'mine') === 'theirs' ? 'theirs' : 'mine';

            if ($settled[$field] === 'theirs') {
                $this->coeditWrite([$field => $this->coeditTheirs[$field] ?? null], $this->coeditTheirsAside);
            }
        }

        // Settled: from here on their value is the base, so the save only sees your choice.
        $this->coeditRebase([...$this->coeditBase, ...Arr::only($this->coeditTheirs, $this->coeditConflicts)]);

        $this->coeditTheirs = [];
        $this->coeditTheirsAside = [];
        $this->coeditConflicts = [];

        $this->coeditSettled = [...$this->coeditSettled, ...$settled];

        $this->coeditResolving = true;

        try {
            $this->save(...$this->coeditSaveArguments);
        } finally {
            $this->coeditResolving = false;
        }
    }

    /**
     * Merge the database into the form; false when the dialog has to settle conflicts first.
     */
    protected function coeditMergeBeforeSave(): bool
    {
        $this->coeditCheckLocale();

        try {
            $theirs = $this->coeditTheirs();
        } catch (ModelNotFoundException) {
            $this->coeditNotifyDeleted();

            return false;
        }

        $this->coeditRefreshAside();

        if ($theirs === null) {
            return $this->coeditMerged = true;
        }

        $result = ThreeWayMerge::merge($this->coeditBase, $this->coeditMine(), $theirs);
        $name = $this->coeditTheirName();

        $this->coeditWrite(Arr::only($result->data, $result->taken), $this->coeditAside);
        // What is in the database now is the base for every field not in conflict,
        // so the save afterwards only reports the fields you changed.
        $this->coeditRebase([...$this->coeditBase, ...Arr::except($theirs, $result->conflicts)]);
        $this->coeditAcknowledge(advanceVersion: false);

        if ($result->taken !== []) {
            $this->coeditTaken = [...$this->coeditTaken, ...array_fill_keys($result->taken, $name)];

            Notification::make()
                ->title(__('filament-coedit::coedit.taken.title', ['name' => e($name)]))
                ->body(__('filament-coedit::coedit.taken.body', ['fields' => $this->coeditLabels($result->taken)]))
                ->info()
                ->send();
        }

        if (! $result->hasConflicts()) {
            return $this->coeditMerged = true;
        }

        // Your own input before any dialog, as Filament's save would check it, so an
        // invalid form never opens the dialog and then fails behind it.
        $this->callHook('beforeValidate');
        $this->form->validate();

        ConflictDetected::dispatch($this->getRecord(), CoEdit::user(), $result->conflicts, $name);

        $this->coeditConflicts = $result->conflicts;
        $this->coeditTheirs = Arr::only($theirs, $result->conflicts);
        $this->coeditTheirsAside = Arr::only($this->coeditAside, $result->conflicts);
        $this->coeditConflictLocale = $this->coeditActiveLocale();

        if ($this->coeditResolving) {
            // A newer save conflicts while the dialog is submitting: Filament would
            // unmount a dialog replaced by itself, so refill it in place (its fields
            // come from the conflict list) and stay open. The choices just made do not stand.
            $this->getMountedActionSchema()?->fill();
            $this->coeditSettled = array_diff_key($this->coeditSettled, array_flip($result->conflicts));

            throw new Halt;
        }

        if (! $this->coeditCalledFromAction()) {
            // Called directly (the Save button, or Cmd+S while a modal is open).
            $this->mountedActions === []
                ? $this->mountAction('coeditResolve')
                : $this->replaceMountedAction('coeditResolve');

            return false;
        }

        // Started from an action (a header "Save and publish"): replace it, as Filament
        // unmounts an action once it is done, and the dialog with it; and stop that
        // action, so nothing it does after the save runs on a save that did not.
        $this->replaceMountedAction('coeditResolve');

        throw new Halt;
    }

    /**
     * Called by the service provider when Filament reports this page saved the record.
     */
    public function coeditAfterSave(): void
    {
        // Only after this page's own merged save: not for a header action's
        // save, and not when called from the browser.
        if (! $this->coeditMerged) {
            return;
        }

        $this->coeditMerged = false;

        // Told once the save is committed: a save that fails or rolls back merged
        // nothing, and what it would have told waits for the next one.
        if ($this->coeditTaken !== [] || $this->coeditSettled !== []) {
            $events = [];

            foreach (array_unique($this->coeditTaken) as $name) {
                $events[] = new ChangesMerged($this->getRecord(), CoEdit::user(), array_keys($this->coeditTaken, $name, strict: true), $name);
            }

            if ($this->coeditSettled !== []) {
                $events[] = new ConflictResolved($this->getRecord(), CoEdit::user(), $this->coeditSettled);
            }

            $this->getRecord()->getConnection()->afterCommit(function () use ($events): void {
                $this->coeditTaken = [];
                $this->coeditSettled = [];

                array_map(event(...), $events);
            });
        }

        $mine = $this->coeditFields();

        // The record as saved, filled through the form: what the save wrote can differ
        // from the form (mutateFormDataBeforeSave(), dehydrateStateUsing()), and the next
        // merge must not mistake that for someone else's change. The save lock is still
        // held, so this is this page's own save.
        try {
            $saved = $this->coeditFilled();
        } catch (ModelNotFoundException) {
            // Deleted by the save itself (an observer, afterSave()): nothing left to merge with.
            $saved = $mine;
        }

        $changed = ThreeWayMerge::changed($this->coeditBase, $saved, exact: true);

        $this->coeditBase = $saved;
        $this->coeditSaved = [];

        // ...and the form, as you left it, still counts as untouched by you.
        foreach ($mine as $field => $value) {
            if (! ThreeWayMerge::untouched($saved[$field] ?? null, $value)) {
                $this->coeditSaved[$field] = $this->coeditPrint($value);
            }
        }

        if ($changed === []) {
            return;
        }

        $labels = array_map(
            fn (string $field): string => $this->coeditLabel($field),
            array_values(array_intersect(array_keys($mine), $changed)),
        );

        // Announced once the save is committed, so nobody pulls the version before it.
        $this->getRecord()->getConnection()->afterCommit(function () use ($labels): void {
            $save = CoEdit::store()->recordSave($this->getCoEditRoom(), CoEdit::who(), $labels, $this->coeditOrigin);

            $this->coeditVersion = $save['version'];

            $this->dispatch('coedit-acknowledged', version: $save['version'], room: PresenceStore::opaque($this->getCoEditRoom()))->to(Presence::class);
            $this->js("window.dispatchEvent(new CustomEvent('filament-coedit-seen'))");
        });
    }

    /**
     * The record as it is in the database now, filled through this form, or
     * null when it matches what the form was loaded from.
     *
     * @return array<string, mixed>|null
     */
    protected function coeditTheirs(): ?array
    {
        $theirs = $this->coeditFilled();

        return ThreeWayMerge::changed($this->coeditBase, $theirs, exact: true) === [] ? null : $theirs;
    }

    /**
     * The record read again and filled through this form, leaving the form as it was.
     *
     * @return array<string, mixed>
     */
    protected function coeditFilled(): array
    {
        $this->getRecord()->refresh();

        // The way the page filled itself at mount (beforeFill/afterFill hooks and any
        // fillForm() override included), so a value set there is no change of theirs.
        return $this->coeditPreservingPage(function (): array {
            $this->fillForm();

            $this->coeditAside = $this->coeditAsideNow();

            return $this->coeditFields();
        });
    }

    /**
     * Run a fill and put the page back as it was. Every public property, not only the
     * form: fillForm() may reset page state too (a page's own fillForm() resetting a
     * locale or a tab), and the save that follows must run on the page as the person
     * left it. Objects are put back as they are: a change the fill makes inside one stays.
     *
     * @template T
     *
     * @param  Closure(): T  $fill
     * @return T
     */
    protected function coeditPreservingPage(Closure $fill): mixed
    {
        $properties = array_filter(
            (new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $property): bool => ! $property->isStatic() && ! $property->isReadOnly(),
        );

        $page = [];

        foreach ($properties as $property) {
            if ($property->isInitialized($this)) {
                $page[$property->getName()] = $property->getValue($this);
            }
        }

        try {
            return $fill();
        } finally {
            foreach ($properties as $property) {
                if (array_key_exists($property->getName(), $page)) {
                    $property->setValue($this, $page[$property->getName()]);
                } elseif ($property->isInitialized($this) && $property->hasType()) {
                    unset($this->{$property->getName()});
                }
            }
        }
    }

    /**
     * The form's fields for a merge, with each field still as your last save left
     * it in the form put back to what that save stored: you have not touched it.
     *
     * @return array<string, mixed>
     */
    protected function coeditMine(): array
    {
        $mine = $this->coeditFields();

        foreach ($this->coeditSaved as $field => $print) {
            if (array_key_exists($field, $mine) && hash_equals($print, $this->coeditPrint($mine[$field]))) {
                $mine[$field] = $this->coeditBase[$field] ?? null;
            }
        }

        return $mine;
    }

    /**
     * Move the merge base. A field whose base changes forgets how your last save
     * stored it: from here on the form is compared with the new base as it is.
     *
     * @param  array<string, mixed>  $base
     */
    protected function coeditRebase(array $base): void
    {
        foreach (array_keys($this->coeditSaved) as $field) {
            if (ThreeWayMerge::normalize($base[$field] ?? null, exact: true) !== ThreeWayMerge::normalize($this->coeditBase[$field] ?? null, exact: true)) {
                unset($this->coeditSaved[$field]);
            }
        }

        $this->coeditBase = $base;
    }

    /** A hash, not the value: the form may hold a password the save stored hashed. */
    protected function coeditPrint(mixed $value): string
    {
        // Exactly, as the form's own values compare; prefixed, so no other HMAC under the app key matches it.
        return hash_hmac('sha256', 'filament-coedit:print:'.ThreeWayMerge::normalize($value, exact: true), (string) config('app.key'));
    }

    /**
     * The form's fields by path. Only the values the form edits take part: ids,
     * timestamps, other attributes the form carries along and ignored fields are
     * not merged.
     *
     * @return array<string, mixed>
     */
    protected function coeditFields(): array
    {
        $fields = FormFields::only($this->form, $this->data ?? [], $this->getCoEditIgnoredFields());
        $translatable = $this->coeditTranslatable();

        if ($translatable === []) {
            return $fields;
        }

        $active = (string) $this->activeLocale;

        foreach ($translatable as $attribute) {
            if (array_key_exists($attribute, $fields)) {
                $fields["{$attribute}@{$active}"] = $fields[$attribute];
            }

            unset($fields[$attribute]);

        }

        // The other locales wait aside in the shape the plugin saves (raw, or dehydrated
        // after a switch); compared in the shape the form holds them, as the active one.
        foreach ($this->otherLocaleData as $locale => $values) {
            if ((string) $locale === $active || ! is_array($values)) {
                continue;
            }

            foreach ($this->coeditHydrated(Arr::only($values, $translatable)) as $attribute => $value) {
                $fields["{$attribute}@{$locale}"] = $value;
            }
        }

        return $fields;
    }

    protected function coeditActiveLocale(): ?string
    {
        return $this->getCoEditTranslatableAttributes() === [] ? null : (string) $this->activeLocale;
    }

    /**
     * The active locale comes from the browser: one the page does not offer is never
     * filled, so nothing could be merged against it.
     */
    protected function coeditCheckLocale(): void
    {
        if ($this->getCoEditTranslatableAttributes() !== [] && method_exists($this, 'getTranslatableLocales')) {
            abort_unless(in_array($this->activeLocale, $this->getTranslatableLocales(), strict: true), 422);
        }
    }

    /**
     * Values of translatable attributes as the form holds them once filled.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function coeditHydrated(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->coeditPreservingPage(function () use ($values): array {
            $this->form->fillPartially($values, array_keys($values));

            return Arr::only($this->data ?? [], array_keys($values));
        });
    }

    /**
     * The other locales as they wait aside now, every translatable attribute of them.
     *
     * @return array<string, mixed> `attribute@locale` => value
     */
    protected function coeditAsideNow(): array
    {
        $aside = [];

        if ($this->getCoEditTranslatableAttributes() === []) {
            return $aside;
        }

        foreach ($this->otherLocaleData as $locale => $values) {
            if ((string) $locale === (string) $this->activeLocale || ! is_array($values)) {
                continue;
            }

            foreach (Arr::only($values, $this->getCoEditTranslatableAttributes()) as $attribute => $value) {
                $aside["{$attribute}@{$locale}"] = $value;
            }
        }

        return $aside;
    }

    /**
     * The plugin saves every translatable attribute of every other locale, also the ones
     * this form leaves out: those take what is in the database now, never the page's copy
     * from when it loaded. Ignored ones save as in plain Filament.
     */
    protected function coeditRefreshAside(): void
    {
        $merged = $this->coeditTranslatable();
        $ignored = $this->getCoEditIgnoredFields();

        foreach ($this->coeditAside as $field => $value) {
            $attribute = Str::beforeLast($field, '@');

            if (! in_array($attribute, $merged, strict: true) && ! in_array($attribute, $ignored, strict: true)) {
                $this->otherLocaleData[Str::afterLast($field, '@')][$attribute] = $value;
            }
        }
    }

    /**
     * Put their values into the form, by path; another locale's into the data it waits
     * in, in the shape it waits there (`$aside`), which the plugin saves as it is.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $aside  their other locales as the plugin keeps them
     */
    protected function coeditWrite(array $values, array $aside = []): void
    {
        foreach ($values as $field => $value) {
            [$path, $locale] = $this->coeditPathOf($field);

            if ($locale !== null && $locale !== (string) $this->activeLocale) {
                $this->otherLocaleData[$locale][$path] = array_key_exists($field, $aside) ? $aside[$field] : $value;

                continue;
            }

            data_set($this->data, $path, $value);
        }
    }

    /**
     * Translatable attributes the form edits and does not ignore.
     *
     * @return list<string>
     */
    protected function coeditTranslatable(): array
    {
        $attributes = $this->getCoEditTranslatableAttributes();

        return $attributes === [] ? [] : array_values(array_intersect($attributes, FormFields::names($this->form, $this->getCoEditIgnoredFields())));
    }

    /**
     * A merge field's path in the form, and its locale for a translatable one.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function coeditPathOf(string $field): array
    {
        if (str_contains($field, '@') && in_array($attribute = Str::beforeLast($field, '@'), $this->coeditTranslatable(), strict: true)) {
            return [$attribute, Str::afterLast($field, '@')];
        }

        return [$field, null];
    }

    protected function coeditLabel(string $field): string
    {
        [$path, $locale] = $this->coeditPathOf($field);

        return FormFields::label($this->form, $path).($locale !== null ? ' ('.Str::upper($locale).')' : '');
    }

    /**
     * @param  list<string>  $fields
     */
    protected function coeditLabels(array $fields): string
    {
        return implode(', ', array_map($this->coeditLabel(...), $fields));
    }

    protected function coeditNotifyDeleted(): void
    {
        Notification::make()
            // Deletes are not recorded, so the last person who saved may not be the one.
            ->title(__('filament-coedit::coedit.deleted', ['name' => __('filament-coedit::coedit.someone')]))
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Whether this save runs inside an action's call (a header "Save and publish"):
     * read from the stack, not by overriding callMountedAction(), so a page that
     * overrides it itself is still seen. Only asked at a conflict.
     */
    protected function coeditCalledFromAction(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['function'] ?? null) === 'callMountedAction' && ($frame['object'] ?? null) === $this) {
                return true;
            }
        }

        return false;
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

        $this->dispatch('coedit-acknowledged', version: $version, room: PresenceStore::opaque($this->getCoEditRoom()))->to(Presence::class);
        $this->js("window.dispatchEvent(new CustomEvent('filament-coedit-seen'))");
    }
}
