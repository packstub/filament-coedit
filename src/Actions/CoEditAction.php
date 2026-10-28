<?php

namespace Packstub\CoEdit\Actions;

use Closure;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use LogicException;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Events\ChangesMerged;
use Packstub\CoEdit\Events\ConflictDetected;
use Packstub\CoEdit\Merge\ThreeWayMerge;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Support\FormFields;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Co-Edit for modal edits: `EditAction::make()->coEdit()` in relation
 * managers, simple (modal) resources and table record actions.
 *
 * The form as it was filled is fingerprinted into the modal's own state.
 * On submit the record is filled again: fields only the other person changed
 * are left out of the update (their value stays), and fields you both
 * changed stop the save once, with their values in a notice; submitting
 * again keeps yours. The avatars of everyone on the record sit in the modal.
 *
 * The merge runs inside the save, after your `before()`, so `before()` and
 * `after()` can be chained in any order. `afterFormFilled()` set after
 * `coEdit()` replaces the fingerprinting, which is reported, never ignored.
 */
class CoEditAction
{
    public const STATE_KEY = '__coedit';

    /**
     * @param  list<string>  $ignore  fields left out of the merge
     */
    public static function configure(EditAction $action, array $ignore = [], bool $presence = true): EditAction
    {
        $hook = fn (string $name): Closure|string|null => (fn () => $this->{$name})->call($action);

        $afterFormFilled = $hook('afterFormFilled');
        $save = $hook('action');

        $action->afterFormFilled(function (EditAction $action, Model $record) use ($afterFormFilled, $ignore): void {
            if ($schema = self::modalSchema($action)) {
                $schema->rawState([
                    ...$schema->getRawState(),
                    self::STATE_KEY => [
                        'version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0),
                        'base' => self::fingerprints($filled = FormFields::only($schema, $schema->getRawState(), $ignore)),
                        'exact' => self::fingerprints($filled, exact: true),
                    ],
                ]);
            }

            if ($afterFormFilled) {
                $action->evaluate($afterFormFilled);
            }
        });

        // The merge wraps the save itself, not before() and after(): those take one
        // callback each, and one chained after coEdit() would replace the merge.
        $action->action(function (EditAction $action, Model $record) use ($save, $ignore): mixed {
            $schema = self::modalSchema($action);

            // As Filament calls it: a method name on the page, and the modal's form injected.
            $save = is_string($save) ? Closure::fromCallable([$action->getLivewire(), $save]) : $save;
            $injected = ['form' => $schema, 'schema' => $schema];

            if (! $schema) {
                return $save ? $action->evaluate($save, $injected) : null;
            }

            $room = PresenceStore::room($record);

            // One save per record at a time, as on edit pages. Released once the update
            // is committed; when that never happens (a halt, an exception, a rollback),
            // by the end of the request.
            try {
                $release = CoEdit::store()->acquireSaveLock($room);
            } catch (LockTimeoutException) {
                Notification::make()->title(__('filament-coedit::coedit.busy'))->warning()->send();

                $action->halt();
            }

            defer($release, 'filament-coedit-save-lock:'.$room, always: true);

            try {
                $labels = self::merge($action, $schema, $record, $ignore);
                $result = $save ? $action->evaluate($save, $injected) : null;
            } catch (Throwable $exception) {
                $release();

                throw $exception;
            }

            // Announced and unlocked once the update is committed.
            $record->getConnection()->afterCommit(function () use ($room, $labels, $release): void {
                if ($labels !== []) {
                    CoEdit::store()->recordSave($room, CoEdit::who(), $labels);
                }

                $release();
            });

            return $result;
        });

        if ($presence) {
            $action->modalContentFooter(fn (Model $record) => view('filament-coedit::hook', [
                'room' => PresenceStore::room($record),
                'mode' => 'editing',
                'version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0),
                'context' => 'modal',
            ]));
        }

        return $action;
    }

    /**
     * The modal's form. An injected `$schema` is the schema the action sits
     * in (none for a table action), not the one it opens.
     */
    protected static function modalSchema(EditAction $action): ?Schema
    {
        $livewire = $action->getLivewire();
        $name = method_exists($livewire, 'getMountedActionSchemaName') ? $livewire->getMountedActionSchemaName() : null;

        return $name ? $livewire->getSchema($name) : null;
    }

    /**
     * Merge the database into the action's data, or halt once on a conflict.
     *
     * @param  list<string>  $ignore
     * @return list<string> labels of the fields this save changes
     */
    protected static function merge(EditAction $action, Schema $schema, Model $record, array $ignore): array
    {
        $raw = $schema->getRawState();
        $state = $raw[self::STATE_KEY] ?? null;
        $mine = FormFields::only($schema, $raw, $ignore);

        if (! is_array($state) || ! is_array($state['base'] ?? null)) {
            // Saving without a merge would overwrite the others unseen: say so.
            $exception = new LogicException('Co-Edit could not merge this modal edit: its form was filled without coEdit()\'s fingerprint. Call afterFormFilled() before coEdit(), not after it.');

            app()->hasDebugModeEnabled() ? throw $exception : report($exception);

            return self::labelsOfChanged($schema, [], $mine);
        }

        $base = array_map(fn (mixed $value): string => is_string($value) ? $value : '', $state['base']);
        $exact = array_map(fn (mixed $value): string => is_string($value) ? $value : '', is_array($state['exact'] ?? null) ? $state['exact'] : []);
        $loaded = $exact;

        // Their version, filled through the same form, then the person's own state put back.
        $record->refresh();
        $action->mount(['schema' => $schema]);
        $theirs = FormFields::only($schema, $schema->getRawState(), $ignore);
        $schema->rawState($raw);

        $theirsPrints = self::fingerprints($theirs);
        $theirsExact = self::fingerprints($theirs, exact: true);
        $minePrints = self::fingerprints($mine);
        $taken = [];
        $conflicts = [];

        foreach ($theirsPrints as $field => $print) {
            $basePrint = $base[$field] ?? self::fingerprint(null);

            // Base and theirs both come from the database: exactly, so "1.1" to "1.10" is theirs.
            if ($theirsExact[$field] === ($exact[$field] ?? self::fingerprint(null, exact: true))) {
                continue;
            }

            $minePrint = $minePrints[$field] ?? null;

            if ($minePrint === $basePrint) {
                $taken[] = $field;
            } elseif ($minePrint !== $print) {
                $conflicts[] = $field;
            }
        }

        $name = self::theirName($record, (int) ($state['version'] ?? 0));
        $data = $action->getData();

        if ($taken !== []) {
            // Left out of the update, the refreshed record keeps their value; the form shows it too.
            foreach ($taken as $field) {
                unset($data[$field]);
                $raw[$field] = $theirs[$field] ?? null;
                $base[$field] = $theirsPrints[$field];
                $exact[$field] = $theirsExact[$field];
            }

            $action->data($data, shouldMutate: false);

            ChangesMerged::dispatch($record, auth()->user(), $taken, $name);

            Notification::make()
                ->title(__('filament-coedit::coedit.taken.title', ['name' => e($name)]))
                ->body(__('filament-coedit::coedit.taken.body', ['fields' => FormFields::labels($schema, $taken)]))
                ->info()
                ->send();
        }

        if ($conflicts === []) {
            $schema->rawState($raw);

            return self::labelsOfChanged($schema, $loaded, array_diff_key($mine, array_flip($taken)));
        }

        ConflictDetected::dispatch($record, auth()->user(), $conflicts, $name);

        // Seen now: their value becomes the base, so submitting again keeps yours.
        foreach ($conflicts as $field) {
            $base[$field] = $theirsPrints[$field];
            $exact[$field] = $theirsExact[$field];
        }

        $raw[self::STATE_KEY] = ['version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0), 'base' => $base, 'exact' => $exact];
        $schema->rawState($raw);

        $lines = array_map(
            fn (string $field): string => e(FormFields::label($schema, $field)).': '.e(FormFields::display($schema, $field, $theirs[$field] ?? null)),
            $conflicts,
        );

        Notification::make()
            ->title(__('filament-coedit::coedit.conflict.heading', ['name' => e($name)]))
            ->body(new HtmlString(e(__('filament-coedit::coedit.modal.body', ['name' => $name])).'<br>'.implode('<br>', $lines)))
            ->warning()
            ->persistent()
            ->send();

        $action->halt();
    }

    /**
     * Exactly, so "1.1" to "1.10" is announced; "10" typed over "10.00" is too.
     *
     * @param  array<string, string>  $base  exact fingerprints
     * @param  array<string, mixed>  $mine
     * @return list<string>
     */
    protected static function labelsOfChanged(Schema $schema, array $base, array $mine): array
    {
        $changed = [];

        foreach (self::fingerprints($mine, exact: true) as $field => $print) {
            if ($print !== ($base[$field] ?? null)) {
                $changed[] = FormFields::label($schema, $field);
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    protected static function fingerprints(array $values, bool $exact = false): array
    {
        return array_map(fn (mixed $value): string => self::fingerprint($value, $exact), $values);
    }

    /**
     * Hashed: the modal's state travels with every request, and a rich text field
     * would otherwise ride along in full, once per kind of comparison.
     */
    protected static function fingerprint(mixed $value, bool $exact = false): string
    {
        return hash('xxh128', ThreeWayMerge::normalize($value, $exact));
    }

    protected static function theirName(Model $record, int $version): string
    {
        $save = CoEdit::store()->lastSave(PresenceStore::room($record));

        return $save !== null && $save['version'] > $version && $save['user'] !== CoEdit::who()['user']
            ? $save['name']
            : __('filament-coedit::coedit.someone');
    }
}
