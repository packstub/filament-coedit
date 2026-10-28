<?php

namespace Packstub\CoEdit\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Events\ChangesMerged;
use Packstub\CoEdit\Events\ConflictDetected;
use Packstub\CoEdit\Events\ConflictResolved;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Merge\ThreeWayMerge;
use Packstub\CoEdit\Support\FormFields;

/**
 * For EditRecord pages: presence in the header, and a save that merges.
 *
 * The form as it was loaded is kept as the merge base. On save the record is
 * read again and filled through the same form; fields only the other person
 * changed keep their value, fields only you changed take yours, and fields
 * you both changed open a dialog to pick per field. Nobody waits on a lock.
 *
 * A page that overrides save() itself calls coeditMergeBeforeSave() first and
 * stops when it returns false, ideally inside CoEdit::store()->whileSaving().
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

    /** True while the dialog's own submit is saving, for this request only. */
    protected bool $coeditResolving = false;

    /**
     * True once this request's save has merged, until Filament reports it saved.
     * Filament also reports saves of header actions (a CreateAction) for this
     * page; those must not move the merge base onto unsaved edits.
     */
    protected bool $coeditMerged = false;

    public function mountInteractsWithCoEdit(): void
    {
        $this->coeditBase = $this->coeditFields($this->data ?? []);
        $this->coeditVersion = (int) (CoEdit::store()->lastSave($this->getCoEditRoom())['version'] ?? 0);
    }

    public function getCoEditMode(): string
    {
        return 'editing';
    }

    /**
     * Fields left out of the merge: your value is saved as in plain Filament.
     * For fields another process owns (a counter, a status a job sets) or
     * that are derived from other fields.
     *
     * @return list<string>
     */
    public function getCoEditIgnoredFields(): array
    {
        return [];
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
     * Bring the other person's saved changes into the open form without saving.
     * Fields you have changed yourself stay as you left them.
     */
    public function coeditPull(): void
    {
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

        $result = ThreeWayMerge::merge($this->coeditBase, $this->coeditFields($this->data), $theirs);

        $this->data = [...$this->data, ...$result->data];
        $this->coeditBase = [...$theirs, ...Arr::only($this->coeditBase, $result->conflicts)];
        $this->coeditAcknowledge(advanceVersion: ! $result->hasConflicts());

        Notification::make()
            ->title(__('filament-coedit::coedit.pull.title'))
            ->body($result->hasConflicts()
                ? __('filament-coedit::coedit.pull.conflicts', ['fields' => FormFields::labels($this->form, $result->conflicts)])
                : ($result->taken === [] ? null : __('filament-coedit::coedit.pull.taken', ['fields' => FormFields::labels($this->form, $result->taken)])))
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
                    ->label(FormFields::label($this->form, $field))
                    ->options([
                        'mine' => __('filament-coedit::coedit.conflict.mine'),
                        'theirs' => __('filament-coedit::coedit.conflict.theirs', ['name' => $this->coeditTheirName()]),
                    ])
                    ->descriptions([
                        'mine' => FormFields::display($this->form, $field, $this->data[$field] ?? null),
                        'theirs' => FormFields::display($this->form, $field, $this->coeditTheirs[$field] ?? null),
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
        // The dialog can be mounted from the browser at any time; without conflicts it has nothing to settle.
        if ($this->coeditConflicts === []) {
            return;
        }

        $settled = [];

        foreach ($this->coeditConflicts as $field) {
            $settled[$field] = ($choices[$field] ?? 'mine') === 'theirs' ? 'theirs' : 'mine';

            if ($settled[$field] === 'theirs') {
                $this->data[$field] = $this->coeditTheirs[$field] ?? null;
            }

            // Settled: from here on their value is the base, so the save only sees your choice.
            $this->coeditBase[$field] = $this->coeditTheirs[$field] ?? null;
        }

        $this->coeditTheirs = [];
        $this->coeditConflicts = [];

        ConflictResolved::dispatch($this->getRecord(), auth()->user(), $settled);

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
        try {
            $theirs = $this->coeditTheirs();
        } catch (ModelNotFoundException) {
            $this->coeditNotifyDeleted();

            return false;
        }

        if ($theirs === null) {
            return $this->coeditMerged = true;
        }

        $result = ThreeWayMerge::merge($this->coeditBase, $this->coeditFields($this->data), $theirs);
        $name = $this->coeditTheirName();

        $this->data = [...$this->data, ...$result->data];
        // What is in the database now is the base for every field not in conflict,
        // so the save afterwards only reports the fields you changed.
        $this->coeditBase = [...$this->coeditBase, ...Arr::except($theirs, $result->conflicts)];
        $this->coeditAcknowledge(advanceVersion: false);

        if ($result->taken !== []) {
            ChangesMerged::dispatch($this->getRecord(), auth()->user(), $result->taken, $name);

            Notification::make()
                ->title(__('filament-coedit::coedit.taken.title', ['name' => e($name)]))
                ->body(__('filament-coedit::coedit.taken.body', ['fields' => FormFields::labels($this->form, $result->taken)]))
                ->info()
                ->send();
        }

        if (! $result->hasConflicts()) {
            return $this->coeditMerged = true;
        }

        ConflictDetected::dispatch($this->getRecord(), auth()->user(), $result->conflicts, $name);

        $this->coeditConflicts = $result->conflicts;
        $this->coeditTheirs = Arr::only($theirs, $result->conflicts);

        if ($this->coeditResolving) {
            // A newer save conflicts while the dialog is submitting: Filament would
            // unmount a dialog replaced by itself, so refill it in place (its fields
            // come from the conflict list) and stay open.
            $this->getMountedActionSchema()?->fill();

            throw new Halt;
        }

        $this->mountAction('coeditResolve');

        return false;
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

        // The record as saved, filled through the form: what the save wrote can differ
        // from the form (mutateFormDataBeforeSave(), dehydrateStateUsing()), and the next
        // merge must not mistake that for someone else's change. The save lock is still
        // held, so this is this page's own save.
        $saved = $this->coeditFilled();
        $changed = ThreeWayMerge::changed($this->coeditBase, $saved);

        $this->coeditBase = $saved;

        if ($changed === []) {
            return;
        }

        $labels = array_map(fn (string $field): string => FormFields::label($this->form, $field), $changed);

        // Announced once the save is committed, so nobody pulls the version before it.
        $this->getRecord()->getConnection()->afterCommit(function () use ($labels): void {
            $save = CoEdit::store()->recordSave($this->getCoEditRoom(), CoEdit::who(), $labels);

            $this->coeditVersion = $save['version'];

            $this->dispatch('coedit-acknowledged', version: $save['version'])->to(Presence::class);
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

        return ThreeWayMerge::changed($this->coeditBase, $theirs) === [] ? null : $theirs;
    }

    /**
     * The record read again and filled through this form, leaving the form as it was.
     *
     * @return array<string, mixed>
     */
    protected function coeditFilled(): array
    {
        $mine = $this->data;

        $this->getRecord()->refresh();

        // The way the page filled itself at mount (beforeFill/afterFill hooks and any
        // fillForm() override included), so a value set there is no change of theirs.
        $this->fillForm();
        $filled = $this->coeditFields($this->data ?? []);
        $this->data = $mine;

        return $filled;
    }

    /**
     * Only the values the form edits take part: ids, timestamps, other
     * attributes the form carries along and ignored fields are not merged.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coeditFields(array $data): array
    {
        return FormFields::only($this->form, $data, $this->getCoEditIgnoredFields());
    }

    protected function coeditNotifyDeleted(): void
    {
        Notification::make()
            ->title(__('filament-coedit::coedit.deleted', ['name' => e($this->coeditTheirName())]))
            ->danger()
            ->persistent()
            ->send();
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
