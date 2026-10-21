# Customizing

## Leaving fields out

Some fields belong to someone else: a counter a job updates, or a status a webhook sets. Left out of the merge, they save as they do in plain Filament:

```php
public function getCoEditIgnoredFields(): array
{
    return ['views'];
}
```

## Events

Listen for these to keep an audit trail or notify the person whose change was kept:

| Event | When | Payload |
| --- | --- | --- |
| `Packstub\CoEdit\Events\ChangesMerged` | A save kept fields only the other person changed | `record`, `user`, `fields`, `theirName` |
| `Packstub\CoEdit\Events\ConflictDetected` | A save found fields both changed | `record`, `user`, `fields`, `theirName` |
| `Packstub\CoEdit\Events\ConflictResolved` | The person chose in the dialog | `record`, `user`, `choices` (field => `mine` or `theirs`) |

## Your own save()

If your edit page overrides `save()`, the trait's version is not used. Call the merge yourself and stop when it returns `false` (the dialog is open):

```php
public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
{
    if (! $this->coeditMergeBeforeSave()) {
        return;
    }

    // your save
}
```

## Rooms

A room is one record: its database, its morph class and its key. Including the database keeps two tenants' product #5 apart in database-per-tenant apps. To decide differently, for example per team on a shared database, register a resolver in a service provider:

```php
use Packstub\CoEdit\Presence\PresenceStore;

PresenceStore::resolveRoomUsing(fn (Model $record): string => $record->team_id.':'.$record->getMorphClass().':'.$record->getKey());
```

Presence on a custom Livewire page works the same way: use `HasPresence` and override `getCoEditRoom()`.
