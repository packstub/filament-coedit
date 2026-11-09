# Filament Co-Edit

<div class="filament-hidden">

![Filament Co-Edit: an edit page with Ana in Price and Mihai in Description, their avatars in the header, and a notice that Ana saved the record](https://raw.githubusercontent.com/packstub/art/main/filament-coedit/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-coedit.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-coedit)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-coedit/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-coedit/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/packstub/filament-coedit.svg?style=flat-square)](https://github.com/packstub/filament-coedit/blob/main/LICENSE.md)

</div>

Several people can edit the same Filament record without locking each other out. You see who else is on the record and which field they are typing in. When you save, their changes and yours are merged field by field. Free and open source (MIT).

![Ion types in Description while Ana changes the price and saves; his save keeps her price and his text](https://raw.githubusercontent.com/packstub/art/main/filament-coedit/docs/coedit.gif)

In Filament, the last save wins: when two people have a record open, the second save writes its whole form over the first. A record lock is one way to handle that, with one person editing while the others wait. Co-Edit merges instead, the way git merges two branches. Everyone keeps editing, and a save only asks you to choose when you and someone else changed the *same field*. Locks are a great fit when only one person should touch a record at a time. Co-Edit is for teams who would rather work on it side by side.

## Features

- **[Presence down to the field](#usage)**: avatars of everyone else on the record, a name tag on the field each one is in.
- **[Saves that merge](#how-the-merge-works)**: fields only they changed keep their value, fields only you changed take yours. Nobody's work is lost.
- **[A dialog only for real conflicts](#how-the-merge-works)**: when you both changed the same field, you pick *Keep mine* or *Use Ana's*, field by field.
- **["Ana saved this record"](#usage)**: a notice names who saved and what changed, with a button that pulls their changes into your open form.
- **[Modal edits too](#modal-edits)**: the same presence and merge in relation managers, simple resources and table edit actions.
- **[Who's where in tables](#usage)**: a column with the avatars of the people who have each row open.
- **[Events for your audit log](#events)**: what was kept, what was contested and what each person chose.
- **[Tenant-aware rooms](#rooms)**: presence is kept per database, so the same id in two tenant databases never meets.
- **[No websockets, no migrations](#good-to-know)**: a small Livewire heartbeat and your cache. Nothing to run, no tables to add.
- **[Add a trait](#installation)**: no panel plugin, nothing to configure. Dark mode, reduced motion and screen readers covered; English, Romanian, Russian and German.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 4.13.3+, 5.8.3+ | 12.x, 13.x | 8.3+ |

These minimums are the first Filament releases with the fixes for the multi-factor authentication advisories published for versions up to 4.13.2 and 5.8.2. The suite runs on both minimums and on the latest 4.x and 5.x.

## Installation

```bash
composer require packstub/filament-coedit:^1.0@beta
php artisan filament:assets
```

Co-Edit is in public beta until 1.0; the `@beta` constraint lets Composer install it without changing your project's minimum stability.

## Usage

Add the trait to an edit page:

```php
use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

class EditProduct extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ProductResource::class;
}
```

That gives the page presence, the merge on save, and the "Ana saved this record" notice with its **Get their changes** button when someone else saves. For view pages, which only need presence, use `HasPresence`:

```php
use Packstub\CoEdit\Concerns\HasPresence;

class ViewProduct extends ViewRecord
{
    use HasPresence;
}
```

To show who's on each row, add the column. Poll the table to keep it live:

```php
use Packstub\CoEdit\Tables\PresenceColumn;

return $table
    ->poll('5s')
    ->columns([
        TextColumn::make('name'),
        PresenceColumn::make('presence'),
    ]);
```

### Modal edits

Relation managers, simple resources and any table with an `EditAction` open the record in a modal. Add `coEdit()` at the end of the chain:

```php
use Filament\Actions\EditAction;

->recordActions([
    EditAction::make()->coEdit(),
])
```

The modal gets the avatars and field markers, and its save merges. A modal has no room for a second dialog, so a conflict works in two steps. The first submit stops, and a notice shows their value for each field you both changed. Submit again to keep yours, or close the modal to keep theirs.

The merge runs before the form is validated, as on an edit page, so `before()` and `after()` go anywhere in the chain. `afterFormFilled()`, `beforeFormValidated()` and an `action()` that replaces the save go before `coEdit()`, which wraps them. Set after `coEdit()`, `afterFormFilled()` or `beforeFormValidated()` replaces a part of the merge. The save then fails loudly (in production it is reported) instead of overwriting anyone unseen. An `action()` set after `coEdit()` still merges, but its save is not announced to the others.

`coEdit(ignore: ['slug'])` leaves fields out of the merge. `coEdit(presence: false)` keeps the merge without the avatars.

### Leaving fields out

A field left out of the merge saves as it does in plain Filament: the form's value is written, even if someone else changed it meanwhile. Use it for a field whose value on this form should always win, such as one your page computes from other fields on save. A field a job or a webhook owns (a counter, a status) is safest left in the merge, where their newer value is kept unless you changed it. Safer still, keep it off the form altogether.

```php
protected function getCoEditIgnoredFields(): array
{
    return ['slug']; // set from the title on every save
}
```

### Events

Listen for these to keep an audit trail or notify the person whose change was kept:

| Event | When | Payload |
| --- | --- | --- |
| `Packstub\CoEdit\Events\ChangesMerged` | A save that kept fields only the other person changed is committed | `record`, `user`, `fields`, `theirName` |
| `Packstub\CoEdit\Events\ConflictDetected` | A save found fields both changed | `record`, `user`, `fields`, `theirName` |
| `Packstub\CoEdit\Events\ConflictResolved` | The save with the choices made in the dialog is committed | `record`, `user`, `choices` (field => `mine` or `theirs`) |

### Your own save()

If your edit page overrides `save()`, the trait's version is not used. Call the merge yourself and stop when it returns `false` (the dialog is open). Wrap both in `whileSaving()` so two saves of the record never run at the same time. Call `coeditAfterSave()` once your save is done, so the next merge starts from it; without it, your own save comes back as a conflict.

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

### Rooms

A room is one record as Co-Edit tells it apart from the others: its database, its morph class and its key. Including the database keeps two tenants' product #5 apart in database-per-tenant apps. To decide differently, register a resolver in a service provider. Tenants that share a database name (one Postgres schema per tenant, or databases of the same name on separate servers) need one, or their people see each other on records with the same key. This example keeps rooms apart per team on a shared database:

```php
use Packstub\CoEdit\Presence\PresenceStore;

PresenceStore::resolveRoomUsing(fn (Model $record): string => $record->team_id.':'.$record->getMorphClass().':'.$record->getKey());
```

Presence on another Filament page with a record and a header (a custom resource page) works the same way: use `HasPresence` and override `getCoEditRoom()`. Keep it `protected`; a public method on a Livewire page can be called from the browser.

## How the merge works

When you save, Co-Edit reads the record again, fills it through your form and compares three versions of every field: the form as you opened it, the form as you are saving it, and the record as it is now.

| You changed it | They changed it | Result |
| --- | --- | --- |
| no | no | unchanged |
| yes | no | yours is saved |
| no | yes | theirs is kept, and you're told |
| yes, the same way | yes | saved, no question |
| yes | yes, differently | the dialog asks you |

![The conflict dialog](https://raw.githubusercontent.com/packstub/art/main/filament-coedit/docs/conflict.png)

The comparison ignores differences that only come from how forms handle values: `"12"` and `12`, `10.00` and `10`, `""`, `[]` and `null`, `true` and `"1"`, an enum and its value, a date-time with or without the `T`, the seconds or a zero fraction (one with a zone is compared in the app's time zone), and the fresh UUID keys repeaters and file uploads get on every fill. Anything else is compared exactly: `"+40123"` and `"40123"`, or two notes that start with the same date, are different values.

Text that looks like a number is compared as text wherever Co-Edit can tell that it is text. Both versions from the database compare exactly, so a version field going from `1.1` to `1.10` is their change and kept. Text you typed compares exactly too, so retyping `1.1` as `1.10` is your change, in a text field or an item of a repeater, builder or key-value field. When you both changed a field, your value and theirs are compared as values, so your `10.5` against their `10.50` is the same change and no conflict.

Each form field is one unit, by its path. `meta.color` in a section with its own `statePath()` and `category.name` in a relationship group (`Group::make()->relationship()`) merge on their own, so two people changing two fields of one group never meet. A repeater, a builder, a key-value field or a JSON column a single field edits merges as a whole. A rich editor merges as one field too. Opening it is no change, even though Filament's editor rewrites list items as it loads. Co-Edit knows the editor's own inline nodes; a custom inline node from your own editor extension is treated as a block in that rewrite, so opening a document with one inside a list item may count as your change.

The merge only covers fields on the form. Attributes the form doesn't edit, like timestamps, counters or columns a job updates, are never written by the merge and never reported.

## Configuration

Optional. Publish it with `php artisan vendor:publish --tag=filament-coedit-config`.

| Key | Default | What it does |
| --- | --- | --- |
| `cache_store` | default store | Where presence lives, also set by `FILAMENT_COEDIT_CACHE_STORE`. Every server must share it, so in production use a shared store such as Redis or the database. |
| `heartbeat` | `4` | Seconds between heartbeats. A page also reports at once when its user moves to another field. |
| `timeout` | `20` | Seconds without a heartbeat before someone drops off. A closing tab leaves immediately. Never less than two heartbeats plus a second. |
| `idle` | `600` | Seconds without a keypress, click or pointer move before a page stops reporting in and its person drops off. The next keypress, click or move brings them back. Heartbeats keep the session alive, so this lets an unattended page sign out as it would without Co-Edit. `null` never pauses. |
| `track_fields` | `true` | Show which field each person is in. Off, the avatars stay and the browser never reports focus. |
| `remember_saves_for` | `3600` | Seconds that "Ana saved this record" is remembered for the people still on the record. The merge itself always compares against the database. |
| `leave_middleware` | `[]` | Middleware for the route a closing tab leaves through, outside your panel. If your tenancy scopes the cache per tenant, add the middleware that identifies the tenant (stancl/tenancy: `InitializeTenancyByDomain`); otherwise people drop off after `timeout` instead of at once. The route serves every panel and runs this before the signature check. A middleware that fails on your central domain logs an error for a central panel's beacons, so use one that passes central requests through. |

## Good to know

- A heartbeat is one small Livewire request that doesn't re-render the page. With the default 4 seconds, ten people on a record send 2.5 heartbeats a second between them. Co-Edit reads and writes only the cache on a heartbeat; your session driver, auth and the panel's `getFilamentAvatarUrl()` run as on any request.
- A toggle never conflicts: it only has two values, so if they flipped it, you either left it alone (theirs is kept) or flipped it the same way.
- Relationship selects show related records by name in the dialog, even when their options are searched rather than preloaded.
- A tab in the background skips its heartbeats, so it drops off after `timeout` seconds and comes back as soon as its user switches to it. A person with the record open in another, visible tab stays on. A page nobody has touched for `idle` seconds stops too. A forgotten tab neither keeps the session alive nor loads the server.
- After `idle` the session can expire as usual, so the first keypress or pointer move afterwards may meet Livewire's "page expired" prompt or your login page, as any request would.
- Saves of the same record run one at a time (a lock in the cache store), so two people pressing Save in the same instant are merged one after the other instead of overwriting each other. A save that waits more than 10 seconds for another one stops with a "try again" notice and writes nothing. Without a store that supports locks the saves are not serialised; Octane's store is one that does not. A save holds the lock for at most 30 seconds.
- To compare like with like, the record is filled through your page the same way it was loaded (`fillForm()`), so your `beforeFill()`/`afterFill()` hooks also run when a save merges and on **Get their changes**. The page's public properties are put back afterwards (objects as they are), so keep those hooks to setting page state. Anything else they do (a query, a log line) happens on every merge. A modal's `mountUsing()` and `fillForm()` run again on each submit in the same way.
- Translatable fields (Filament's spatie-translatable plugin, `lara-zeus/spatie-translatable`) merge per locale on edit pages. The title in English and the title in Romanian are two fields, whichever locale each person has open. Ana's Romanian title stays when you save the page in English, and the dialog names the locale ("Title (RO)"). `getCoEditTranslatableAttributes()` says which attributes those are (the resource's, by default). A translatable attribute your form leaves out is saved as it is in the database now, never as your page loaded it. An ignored attribute (`getCoEditIgnoredFields()` takes the attribute, `body`, not `body@ro`) is left to plain Filament in every locale. A modal edit merges the locale it shows.
- Only Filament's own save merges. `saveFormComponentOnly()` (a field that saves its own section) writes as in plain Filament.
- If someone deletes the record while you are editing, your save never recreates it: Filament can no longer load the record and the request stops before anything is written.
- Names and avatars come from your panel (`getFilamentName()`, `getFilamentAvatarUrl()`), so people look the way they look everywhere else in the panel.

## Documentation

The full documentation is at [packstub.dev/docs/filament-coedit](https://packstub.dev/docs/filament-coedit): [getting started](https://packstub.dev/docs/filament-coedit/getting-started), [how the merge works](https://packstub.dev/docs/filament-coedit/how-the-merge-works), [modal edits](https://packstub.dev/docs/filament-coedit/modal-edits), [customizing](https://packstub.dev/docs/filament-coedit/customizing) and [configuration](https://packstub.dev/docs/filament-coedit/configuration).

## Testing

```bash
composer test
```

The suite has 100+ tests: the merge rules and value comparison on their own, presence in the cache, and two or three people editing on real Filament edit pages and modal actions. Those cover relationships, nested and hidden fields, file uploads, a third save landing while the dialog is open, ignored fields, events, tenants and tampering from the browser. `scripts/screenshots.mjs` drives three real browsers through the whole flow and checks every step.

Try it with three people: `composer serve` starts a workbench panel on `http://127.0.0.1:8000/admin` with Products (edit pages) and Suppliers (modal edits). Log in as ion@, ana@ or mihai@example.com, password `password`, in a few private windows.

## License

MIT. See [LICENSE.md](https://github.com/packstub/filament-coedit/blob/main/LICENSE.md).
