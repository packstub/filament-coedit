# Filament Co-Edit

Several people can edit the same Filament record without locking each other out. You see who else is on the record and which field they are typing in. When you save, their changes and yours are merged field by field. Free and open source (MIT).

![Ion types in Description while Ana changes the price and saves; his save keeps her price and his text](https://raw.githubusercontent.com/packstub/art/main/filament-coedit/docs/coedit.gif)

In Filament, the last save wins. When two people have a record open, the second save writes its whole form over the first. A record lock is one way to handle that: one person edits while the others wait. Locks are a great fit when only one person should touch a record at a time.

Co-Edit merges instead, the way git merges two branches. Everyone keeps editing. A save only asks you to choose when you and someone else changed the *same field*. Co-Edit is for teams who would rather work on a record side by side.

- Repository: [github.com/packstub/filament-coedit](https://github.com/packstub/filament-coedit)
- Packagist: [packstub/filament-coedit](https://packagist.org/packages/packstub/filament-coedit)
- Support: [GitHub issues](https://github.com/packstub/filament-coedit/issues)

## What you get

- **[Presence down to the field](getting-started.md)**: avatars of everyone else on the record, a name tag on the field each one is in.
- **[Saves that merge](how-the-merge-works.md)**: fields only they changed keep their value, fields only you changed take yours. Nobody's work is lost.
- **[A dialog only for real conflicts](how-the-merge-works.md)**: when you both changed the same field, you pick *Keep mine* or *Use Ana's*, field by field.
- **["Ana saved this record"](getting-started.md)**: a notice names who saved and what changed, with a button that pulls their changes into your open form.
- **[Modal edits too](modal-edits.md)**: the same presence and merge in relation managers, simple resources and table edit actions.
- **[Who's where in tables](getting-started.md)**: a column with the avatars of the people who have each row open.
- **[Events for your audit log](customizing.md#events)**: what was kept, what was contested and what each person chose.
- **[Tenant-aware rooms](customizing.md#rooms)**: presence is kept per database, so the same id in two tenant databases never meets.
- **[No websockets, no migrations](how-the-merge-works.md#good-to-know)**: a small Livewire heartbeat and your cache. Nothing to run, no tables to add.
- **[Add a trait](installation.md)**: no panel plugin, nothing to configure. Dark mode, reduced motion and screen readers covered; English, Romanian, Russian and German.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 4.13.3+, 5.8.3+ | 12.x, 13.x | 8.3+ |

These minimum versions are the first Filament releases past the multi-factor authentication advisories, which cover versions up to 4.13.2 and 5.8.2. CI runs the test suite on both minimums and on the latest 4.x and 5.x.

Next: [Installation](installation.md).
