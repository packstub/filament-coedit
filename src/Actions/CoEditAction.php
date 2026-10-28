<?php

namespace Packstub\CoEdit\Actions;

use Closure;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
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
 * Call it last in the chain: it wraps the action's `afterFormFilled()`,
 * `before()` and `after()` callbacks that are set at that point.
 */
class CoEditAction
{
    public const STATE_KEY = '__coedit';

    /**
     * @param  list<string>  $ignore  fields left out of the merge
     */
    public static function configure(EditAction $action, array $ignore = [], bool $presence = true): EditAction
    {
        $hook = fn (string $name): ?Closure => (fn () => $this->{$name})->call($action);

        $afterFormFilled = $hook('afterFormFilled');
        $before = $hook('before');
        $after = $hook('after');

        /** @var array<string, list<string>> $changed  labels of the fields this request saves, per record */
        $changed = [];

        /** @var array<string, Closure(): void> $releases  the save lock held between before() and after(), per record */
        $releases = [];

        $action->afterFormFilled(function (EditAction $action, Model $record) use ($afterFormFilled, $ignore): void {
            if ($schema = self::modalSchema($action)) {
                $schema->rawState([
                    ...$schema->getRawState(),
                    self::STATE_KEY => [
                        'version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0),
                        'base' => self::fingerprints(FormFields::only($schema, $schema->getRawState(), $ignore)),
                    ],
                ]);
            }

            if ($afterFormFilled) {
                $action->evaluate($afterFormFilled);
            }
        });

        $action->before(function (EditAction $action, Model $record) use ($before, $ignore, &$changed, &$releases): void {
            if ($schema = self::modalSchema($action)) {
                $room = PresenceStore::room($record);

                // One save per record at a time, as on edit pages. after() releases it
                // once the update is committed; when that never happens (your before()
                // halts, the update throws, the transaction rolls back), the end of the
                // request does.
                try {
                    $release = $releases[$room] = CoEdit::store()->acquireSaveLock($room);
                } catch (LockTimeoutException) {
                    Notification::make()->title(__('filament-coedit::coedit.busy'))->warning()->send();

                    $action->halt();
                }

                defer($release, 'filament-coedit-save-lock:'.$room, always: true);

                try {
                    $changed[$room] = self::merge($action, $schema, $record, $ignore);

                    if ($before) {
                        $action->evaluate($before);
                    }
                } catch (Throwable $exception) {
                    $release();

                    throw $exception;
                }

                return;
            }

            if ($before) {
                $action->evaluate($before);
            }
        });

        $action->after(function (EditAction $action, Model $record) use ($after, &$changed, &$releases): void {
            $room = PresenceStore::room($record);
            $labels = $changed[$room] ?? [];
            $release = $releases[$room] ?? null;

            unset($changed[$room], $releases[$room]);

            // Announced and unlocked once the update is committed.
            $record->getConnection()->afterCommit(function () use ($room, $labels, $release): void {
                if ($labels !== []) {
                    CoEdit::store()->recordSave($room, CoEdit::who(), $labels);
                }

                $release?->__invoke();
            });

            if ($after) {
                $action->evaluate($after);
            }
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
            return self::labelsOfChanged($schema, [], $mine);
        }

        $base = array_map(fn (mixed $value): string => is_string($value) ? $value : '', $state['base']);
        $loaded = $base;

        // Their version, filled through the same form, then the person's own state put back.
        $record->refresh();
        $action->mount(['schema' => $schema]);
        $theirs = FormFields::only($schema, $schema->getRawState(), $ignore);
        $schema->rawState($raw);

        $theirsPrints = self::fingerprints($theirs);
        $minePrints = self::fingerprints($mine);
        $taken = [];
        $conflicts = [];

        foreach ($theirsPrints as $field => $print) {
            $basePrint = $base[$field] ?? ThreeWayMerge::normalize(null);

            if ($print === $basePrint || ($minePrints[$field] ?? null) === $print) {
                continue;
            }

            ($minePrints[$field] ?? null) === $basePrint ? $taken[] = $field : $conflicts[] = $field;
        }

        $name = self::theirName($record, (int) ($state['version'] ?? 0));
        $data = $action->getData();

        if ($taken !== []) {
            // Left out of the update, the refreshed record keeps their value; the form shows it too.
            foreach ($taken as $field) {
                unset($data[$field]);
                $raw[$field] = $theirs[$field] ?? null;
                $base[$field] = $theirsPrints[$field];
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
        }

        $raw[self::STATE_KEY] = ['version' => (int) (CoEdit::store()->lastSave(PresenceStore::room($record))['version'] ?? 0), 'base' => $base];
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
     * @param  array<string, string>  $base
     * @param  array<string, mixed>  $mine
     * @return list<string>
     */
    protected static function labelsOfChanged(Schema $schema, array $base, array $mine): array
    {
        $changed = [];

        foreach (self::fingerprints($mine) as $field => $print) {
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
    protected static function fingerprints(array $values): array
    {
        return array_map(ThreeWayMerge::normalize(...), $values);
    }

    protected static function theirName(Model $record, int $version): string
    {
        $save = CoEdit::store()->lastSave(PresenceStore::room($record));

        return $save !== null && $save['version'] > $version && $save['user'] !== CoEdit::who()['user']
            ? $save['name']
            : __('filament-coedit::coedit.someone');
    }
}
