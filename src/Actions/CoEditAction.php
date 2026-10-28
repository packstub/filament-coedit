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
 * The merge runs before the form is validated and read (relationship fields
 * save as it is read), so `before()` and `after()` can be chained in any
 * order. `afterFormFilled()` or `beforeFormValidated()` set after `coEdit()`
 * replace a part of it, which the save reports instead of ignoring.
 *
 * @internal
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
        $beforeFormValidated = $hook('beforeFormValidated');
        $save = $hook('action');

        /** @var array<string, array{labels: list<string>, event: ?ChangesMerged, release: Closure(): void}> $merged  per record, this request */
        $merged = [];

        $action->afterFormFilled(function (EditAction $action, Model $record) use ($afterFormFilled, $ignore): void {
            if ($schema = self::modalSchema($action)) {
                $schema->rawState([
                    ...$schema->getRawState(),
                    self::STATE_KEY => [
                        'version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0),
                        'base' => self::pack(self::fingerprints($filled = FormFields::only($schema, $schema->getRawState(), $ignore))),
                        'exact' => self::pack(self::fingerprints($filled, exact: true)),
                    ],
                ]);
            }

            if ($afterFormFilled) {
                $action->evaluate($afterFormFilled);
            }
        });

        // Before the form is validated and read, as an edit page merges before its save:
        // reading the form saves its relationship fields, so a merge after that is too late.
        $action->beforeFormValidated(function (EditAction $action, Model $record) use ($beforeFormValidated, $ignore, &$merged): void {
            if ($schema = self::modalSchema($action)) {
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
                    $merged[$room] = [...self::merge($action, $schema, $record, $ignore), 'release' => $release];
                } catch (Throwable $exception) {
                    $release();

                    throw $exception;
                }
            }

            if ($beforeFormValidated) {
                $action->evaluate($beforeFormValidated);
            }
        });

        // The save itself checks that the merge ran: before() and after() can be chained
        // anywhere, and a hook replaced after coEdit() must not save over the others unseen.
        $action->action(function (EditAction $action, Model $record) use ($save, &$merged): mixed {
            $schema = self::modalSchema($action);

            // As Filament calls it: a method name on the page, and the modal's form injected.
            $save = is_string($save) ? Closure::fromCallable([$action->getLivewire(), $save]) : $save;
            $injected = ['form' => $schema, 'schema' => $schema];

            if (! $schema) {
                return $save ? $action->evaluate($save, $injected) : null;
            }

            $room = PresenceStore::room($record);
            $merge = $merged[$room] ?? null;
            unset($merged[$room]);

            if ($merge === null) {
                self::misconfigured('its form was read without coEdit()\'s merge. Call beforeFormValidated() before coEdit(), not after it.');
            }

            try {
                $result = $save ? $action->evaluate($save, $injected) : null;
            } catch (Throwable $exception) {
                $merge && $merge['release']();

                throw $exception;
            }

            // Announced and unlocked once the update is committed.
            if ($merge) {
                $record->getConnection()->afterCommit(function () use ($room, $merge): void {
                    if ($merge['event']) {
                        event($merge['event']);
                    }

                    if ($merge['labels'] !== []) {
                        CoEdit::store()->recordSave($room, CoEdit::who(), $merge['labels']);
                    }

                    $merge['release']();
                });
            }

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
     * @return array{labels: list<string>, event: ?ChangesMerged} labels of the fields this save changes, and what it took from the others
     */
    protected static function merge(EditAction $action, Schema $schema, Model $record, array $ignore): array
    {
        $raw = $schema->getRawState();
        $state = $raw[self::STATE_KEY] ?? null;
        $mine = FormFields::only($schema, $raw, $ignore);

        if (! is_array($state) || ! is_array($state['base'] ?? null)) {
            self::misconfigured('its form was filled without coEdit()\'s fingerprint. Call afterFormFilled() before coEdit(), not after it.');

            return ['labels' => self::labelsOfChanged($schema, [], $mine), 'event' => null];
        }

        $names = FormFields::names($schema, $ignore);
        $base = self::unpack($state['base'], $names);
        $exact = self::unpack(is_array($state['exact'] ?? null) ? $state['exact'] : [], $names);
        $loaded = $exact;

        // Their version, filled through the same form, then the person's own state put back.
        $record->refresh();
        // As Filament mounts it: a mountUsing() closure may ask for `$form` or `$schema`.
        $action->mount(['form' => $schema, 'schema' => $schema]);
        $theirs = FormFields::only($schema, $schema->getRawState(), $ignore);
        $schema->rawState($raw);

        $theirsPrints = self::fingerprints($theirs);
        $theirsExact = self::fingerprints($theirs, exact: true);
        $minePrints = self::fingerprints($mine);
        $mineExact = self::fingerprints($mine, exact: true);
        $taken = [];
        $conflicts = [];

        foreach ($theirsPrints as $field => $print) {
            $basePrint = $base[$field] ?? self::fingerprint(null);

            // Base and theirs both come from the database: exactly, so "1.1" to "1.10" is theirs.
            if ($theirsExact[$field] === ($exact[$field] ?? self::fingerprint(null, exact: true))) {
                continue;
            }

            $minePrint = $minePrints[$field] ?? null;

            // As ThreeWayMerge::untouched(): text you typed compares exactly.
            $untouched = is_string($mine[$field] ?? null)
                ? $mineExact[$field] === ($exact[$field] ?? self::fingerprint(null, exact: true))
                : $minePrint === $basePrint;

            if ($untouched) {
                $taken[] = $field;
            } elseif ($minePrint !== $print) {
                $conflicts[] = $field;
            }
        }

        $name = self::theirName($record, (int) ($state['version'] ?? 0));

        // Kept by a submit that stopped after its merge (a validation error): told with this one.
        $kept = self::kept($state, $names);
        $keep = $taken === [] ? $kept : ['fields' => array_values(array_unique([...$kept['fields'] ?? [], ...$taken])), 'name' => $name];
        $event = ($keep['fields'] ?? []) !== [] ? new ChangesMerged($record, auth()->user(), $keep['fields'], $keep['name'] ?? null) : null;

        if ($taken !== []) {
            // Their value into the form before it is read: the update (and a relationship
            // field's own save) writes what is in the database already.
            foreach ($taken as $field) {
                data_set($raw, $field, $theirs[$field] ?? null);
                $base[$field] = $theirsPrints[$field];
                $exact[$field] = $theirsExact[$field];
            }

            Notification::make()
                ->title(__('filament-coedit::coedit.taken.title', ['name' => e($name)]))
                ->body(__('filament-coedit::coedit.taken.body', ['fields' => FormFields::labels($schema, $taken)]))
                ->info()
                ->send();
        }

        if ($conflicts === []) {
            // The base moves with the form: a save that stops after this (a validation
            // error, a halting before()) must not see their values as yours next time.
            $raw[self::STATE_KEY] = [...$state, 'base' => self::pack($base), 'exact' => self::pack($exact), 'kept' => self::sign($keep)];
            $schema->rawState($raw);

            return ['labels' => self::labelsOfChanged($schema, $loaded, array_diff_key($mine, array_flip($taken))), 'event' => $event];
        }

        ConflictDetected::dispatch($record, auth()->user(), $conflicts, $name);

        // Seen now: their value becomes the base, so submitting again keeps yours.
        foreach ($conflicts as $field) {
            $base[$field] = $theirsPrints[$field];
            $exact[$field] = $theirsExact[$field];
        }

        $raw[self::STATE_KEY] = ['version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0), 'base' => self::pack($base), 'exact' => self::pack($exact), 'kept' => self::sign($keep)];
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
     * Saving without a merge would overwrite the others unseen: say so, loudly
     * while developing, in the error log in production.
     */
    protected static function misconfigured(string $reason): void
    {
        $exception = new LogicException('Co-Edit could not merge this modal edit: '.$reason);

        app()->hasDebugModeEnabled() ? throw $exception : report($exception);
    }

    /**
     * Signed with the app key, as the browser holds it and it ends up in an audit log.
     *
     * @param  array{fields?: list<string>, name?: string}  $kept
     * @return array{fields?: list<string>, name?: string, signature?: string}
     */
    protected static function sign(array $kept): array
    {
        return $kept === [] ? [] : [...$kept, 'signature' => hash_hmac('sha256', json_encode([$kept['fields'] ?? [], $kept['name'] ?? null]) ?: '', (string) config('app.key'))];
    }

    /**
     * What earlier submits of this modal kept from the others, when the signature holds.
     *
     * @param  array<mixed>  $state
     * @param  list<string>  $names
     * @return array{fields?: list<string>, name?: string}
     */
    protected static function kept(array $state, array $names): array
    {
        $kept = $state['kept'] ?? null;

        if (! is_array($kept) || ! is_array($kept['fields'] ?? null) || ! is_string($kept['signature'] ?? null)) {
            return [];
        }

        $kept = ['fields' => array_values(array_filter($kept['fields'], is_string(...))), 'name' => is_string($kept['name'] ?? null) ? $kept['name'] : null];

        if (! hash_equals(self::sign($kept)['signature'] ?? '', $state['kept']['signature'])) {
            return [];
        }

        return ['fields' => array_values(array_intersect($kept['fields'], $names)), 'name' => $kept['name']];
    }

    /**
     * Fingerprints keyed for the modal's state, where a dot in a field's path would nest it.
     *
     * @param  array<string, string>  $prints
     * @return array<string, string>
     */
    protected static function pack(array $prints): array
    {
        return array_combine(array_map(FormFields::key(...), array_keys($prints)), $prints);
    }

    /**
     * The modal's fingerprints by field path again; anything else the browser put there is dropped.
     *
     * @param  array<mixed>  $state
     * @param  list<string>  $names
     * @return array<string, string>
     */
    protected static function unpack(array $state, array $names): array
    {
        $prints = [];

        foreach ($names as $name) {
            $print = $state[FormFields::key($name)] ?? null;

            if (is_string($print)) {
                $prints[$name] = $print;
            }
        }

        return $prints;
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
