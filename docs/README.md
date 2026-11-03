# Filament Co-Edit

Several people can edit the same Filament record without locking each other out. You see who else is on the record and which field they are typing in. When you save, their changes and yours are merged field by field. Free and open source (MIT).

![Ion types in Description while Ana changes the price and saves; his save keeps her price and his text](https://raw.githubusercontent.com/packstub/art/main/filament-coedit/docs/coedit.gif)

In Filament, the last save wins: when two people have a record open, the second save writes its whole form over the first. A record lock is one way to handle that, with one person editing while the others wait. Co-Edit merges instead, the way git merges two branches. Everyone keeps editing, and a save only asks you to choose when you and someone else changed the *same field*. Locks are a great fit when only one person should touch a record at a time. Co-Edit is for teams who would rather work on it side by side.

- Repository: [github.com/packstub/filament-coedit](https://github.com/packstub/filament-coedit)
- Packagist: [packstub/filament-coedit](https://packagist.org/packages/packstub/filament-coedit)
- Support: [GitHub issues](https://github.com/packstub/filament-coedit/issues)

## What you get

- **Presence in the header**: avatars of everyone else on the record, with a pulsing ring on those editing. One avatar per person, however many tabs they have open.
- **Field-level presence**: a coloured outline and a name tag on the field each person is in, so you know Ana is in Price before you start typing there.
- **Saves that merge**: if someone saved while you were editing, the fields only they changed keep their value and the fields only you changed take yours. Neither person's work is lost.
- **A conflict dialog only when needed**: when you both changed the same field, you pick *Keep mine* or *Use Ana's* for each of those fields, and only for those.
- **"Ana saved this record"**: a notice names who saved and which fields changed. **Get their changes** pulls them into your open form without saving. On a view page, **Reload** shows them.
- **Modal edits too**: `EditAction::make()->coEdit()` brings the same merge and presence to relation managers, simple (modal) resources and table edit actions.
- **Who's where in tables**: `PresenceColumn` shows avatars on the rows people have open.
- **Events for your audit log**: `ChangesMerged`, `ConflictDetected` and `ConflictResolved` say what was kept, what was contested and what each person chose.
- **Tenant-aware**: rooms include the record's database, so the same id in two tenant databases never shares presence.
- **No websockets, no migrations**: each open page checks in every few seconds with a Livewire request, and presence is kept in your cache (Redis, database, memcached…). There's no Reverb or Pusher to run and no tables to add.
- **Add a trait**: no panel plugin to register and nothing to configure.
- Dark mode, `prefers-reduced-motion`, a live region that names who is on the record for screen readers; English, Romanian, Russian and German.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 4.13.3+, 5.8.3+ | 12.x, 13.x | 8.3+ |

The floors are the first Filament releases with the fixes for the multi-factor authentication advisories published up to 4.13.2 and 5.8.2. The suite runs on both floors and on the latest 4.x and 5.x.
