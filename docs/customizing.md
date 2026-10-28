# Customizing

## Leaving fields out

A field left out of the merge saves as it does in plain Filament: the form's value is written, whoever changed it meanwhile. Use it for a field whose value on this form should always win, such as one your page computes from other fields on save. A field a job or a webhook owns (a counter, a status) is safest left in the merge, where their newer value is kept unless you changed it, or off the form altogether:

```php
protected function getCoEditIgnoredFields(): array
{
    return ['views'];
}
```

## Events

Listen for these to keep an audit trail or notify the person whose change was kept:

| Event | When | Payload |
| --- | --- | --- |
| `Packstub\CoEdit\Events\ChangesMerged` | A save that kept fields only the other person changed is committed | `record`, `user`, `fields`, `theirName` |
| `Packstub\CoEdit\Events\ConflictDetected` | A save found fields both changed | `record`, `user`, `fields`, `theirName` |
| `Packstub\CoEdit\Events\ConflictResolved` | The save the person chose for in the dialog is committed | `record`, `user`, `choices` (field => `mine` or `theirs`) |

## Your own save()

If your edit page overrides `save()`, the trait's version is not used. Call the merge yourself and stop when it returns `false` (the dialog is open). Wrap both in `whileSaving()` so two saves of the record never run at the same time, and call `coeditAfterSave()` once your save is done so the next merge starts from it (without it, your own save comes back as a conflict):

```php
use Packstub\CoEdit\CoEdit;

public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
{
    CoEdit::store()->whileSaving($this->getCoEditRoom(), function (): void {
        if (! $this->coeditMergeBeforeSave()) {
            return;
        }

        // your save

        // Unless your save went through Filament's (parent::save()), which reports it:
        $this->coeditAfterSave();
    });
}
```

## Rooms

A room is one record: its database, its morph class and its key. Including the database keeps two tenants' product #5 apart in database-per-tenant apps. Tenants that share a database name, such as one Postgres schema per tenant or databases of the same name on separate servers, need the resolver below, or their people see each other on records with the same key. To decide differently, for example per team on a shared database, register a resolver in a service provider:

```php
use Packstub\CoEdit\Presence\PresenceStore;

PresenceStore::resolveRoomUsing(fn (Model $record): string => $record->team_id.':'.$record->getMorphClass().':'.$record->getKey());
```

Presence on another Filament page with a record and a header (a custom resource page) works the same way: use `HasPresence` and override `getCoEditRoom()` (keep it `protected`: a public method on a Livewire page can be called from the browser).
