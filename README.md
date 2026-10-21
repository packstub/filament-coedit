# Filament Co-Edit

<div class="filament-hidden">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-coedit.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-coedit)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-coedit/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-coedit/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/packstub/filament-coedit.svg?style=flat-square)](https://github.com/packstub/filament-coedit/blob/main/LICENSE.md)

</div>

Several people can edit the same Filament record without locking each other out. You see who else is on the record and which field they are typing in. When you save, their changes and yours are merged field by field. Free and open source (MIT).

![Ana is in Price, Mihai is in Description](docs/images/presence.png)

In Filament, the last save wins: when two people have a record open, the second save writes its whole form over the first. A record lock is one way to handle that, with one person editing while the others wait. Co-Edit merges instead, the way git merges two branches. Everyone keeps editing, and a save only asks you to choose when you and someone else changed the *same field*. Locks are a great fit when only one person should touch a record at a time. Co-Edit is for teams who would rather work on it side by side.

## Features

- **Presence in the header**: avatars of everyone else on the record, with a pulsing ring on those editing. One avatar per person, however many tabs they have open.
- **Field-level presence**: a coloured outline and a name tag on the field each person is in, so you know Ana is in Price before you start typing there.
- **Saves that merge**: if someone saved while you were editing, the fields only they changed keep their value and the fields only you changed take yours. Neither person's work is lost.
- **A conflict dialog only when needed**: when you both changed the same field, you pick *Keep mine* or *Use Ana's* for each of those fields, and only for those.
- **"Ana saved this record"**: a notice names who saved and which fields changed. **Get their changes** pulls them into your open form without saving. On a view page, **Reload** shows them.
- **Who's where in tables**: `PresenceColumn` shows avatars on the rows people have open.
- **No websockets, no migrations**: each open page checks in every few seconds with a Livewire request, and presence is kept in your cache (Redis, database, memcached…). There's no Reverb or Pusher to run and no tables to add.
- **Add a trait**: no panel plugin to register and nothing to configure.
- Dark mode, `prefers-reduced-motion`, a live region that names who is on the record for screen readers; English, Romanian, Russian and German.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 4.13+, 5.x | 12.x, 13.x | 8.3+ |

## Installation

```bash
composer require packstub/filament-coedit
php artisan filament:assets
```

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

That gives the page presence and the merge on save. For view pages, which only need presence, use `HasPresence`:

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

## How the merge works

When you save, Co-Edit reads the record again, fills it through your form and compares three versions of every field: the form as you opened it, the form as you are saving it, and the record as it is now.

| You changed it | They changed it | Result |
| --- | --- | --- |
| no | no | unchanged |
| yes | no | yours is saved |
| no | yes | theirs is kept, and you're told |
| yes, the same way | yes | saved, no question |
| yes | yes, differently | the dialog asks you |

![The conflict dialog](docs/images/conflict.png)

The comparison ignores differences that only come from how forms handle values: `"12"` and `12`, `10.00` and `10`, `""`, `[]` and `null`, `true` and `"1"`, an enum and its value, a date-time with or without the `T` and the seconds, and the fresh UUID keys repeaters and file uploads get on every fill. Each top-level form field is one unit. A repeater, a key-value field or a JSON column merges as a whole.

The merge only covers fields on the form. Attributes the form doesn't edit, like timestamps, counters or columns a job updates, are never written by the merge and never reported.

## Configuration

Optional. Publish it with `php artisan vendor:publish --tag=filament-coedit-config`.

| Key | Default | What it does |
| --- | --- | --- |
| `cache_store` | default store | Where presence lives, also set by `FILAMENT_COEDIT_CACHE_STORE`. Every server must share it, so in production use a shared store such as Redis or the database. |
| `heartbeat` | `4` | Seconds between heartbeats. A page also reports at once when its user moves to another field. |
| `timeout` | `20` | Seconds without a heartbeat before someone drops off. A closing tab leaves immediately. |
| `remember_saves_for` | `3600` | Seconds that "Ana saved this record" is remembered for the people still on the record. The merge itself always compares against the database. |

## Good to know

- A heartbeat is one small Livewire request that doesn't re-render the page. With the default 4 seconds, ten people on a record send 2.5 heartbeats a second between them.
- A tab in the background skips its heartbeats, so it drops off after `timeout` seconds and comes back as soon as its user switches to it. A person with the record open in another, visible tab stays on.
- The merge runs in the same request as the save, a few milliseconds before Filament writes. Two saves landing in the same instant can still overwrite each other. Co-Edit covers the minutes someone spends editing, not those last few milliseconds.
- If someone deletes the record while you are editing, your save never recreates it: Filament can no longer load the record and the request stops before anything is written.
- Names and avatars come from your panel (`getFilamentName()`, `getFilamentAvatarUrl()`), so people look the way they look everywhere else in the panel.

## Testing

```bash
composer test
```

Try it with three people: `composer serve` starts a workbench panel on `http://127.0.0.1:8000/admin` (ion@, ana@ and mihai@example.com, password `password`). Open it in a few private windows.

## License

MIT. See [LICENSE.md](LICENSE.md).
