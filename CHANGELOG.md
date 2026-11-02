# Changelog

All notable changes to `packstub/filament-coedit` are documented in this file.

## Unreleased

First release of Filament Co-Edit: edit records together without locks. You see who is on the record down to the field, and saves merge field by field. Requires Filament 4.13.3+ or 5.8.3+.

- Add `InteractsWithCoEdit` to an edit page: changes only someone else made are kept, yours are saved
- A dialog only for fields you both changed, with **Keep mine** or **Use Ana's** per field
- Modal edits too, with `EditAction::make()->coEdit()` (relation managers, simple resources, tables)
- Presence on edit pages, view pages (`HasPresence`) and tables (`PresenceColumn`), kept in your cache
- A notice when someone saves, with **Get their changes** to pull them into your form
- Translatable fields merge per locale; tenant databases never share presence
- In English, Romanian, Russian and German
