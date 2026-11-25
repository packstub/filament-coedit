# Changelog

All notable changes to `packstub/filament-coedit` are documented in this file.

## 1.0.0 - Unreleased

The first stable release, with no changes to how Co-Edit merges since the beta. The public API (the `InteractsWithCoEdit` and `HasPresence` traits, `EditAction::make()->coEdit()`, `PresenceColumn`, the events, the config keys, the `getCoEdit…()` methods a page overrides, `PresenceStore::resolveRoomUsing()` and `whileSaving()`) now follows semantic versioning; methods marked `@internal` are not part of it.

- Install with a plain `composer require packstub/filament-coedit`, no `@beta` constraint
- The package's homepage is its page on packstub.dev

## 1.0.0-beta.1 - 2026-11-09

First release of Filament Co-Edit: edit records together without locks. You see who is on the record down to the field, and saves merge field by field. Requires Filament 4.13.3+ or 5.8.3+.

- Add `InteractsWithCoEdit` to an edit page: changes only someone else made are kept, yours are saved
- A dialog only for fields you both changed, with **Keep mine** or **Use Ana's** per field
- Modal edits too, with `EditAction::make()->coEdit()` (relation managers, simple resources, tables)
- Presence on edit pages, view pages (`HasPresence`) and tables (`PresenceColumn`), kept in your cache
- A notice when someone saves, with **Get their changes** to pull them into your form
- Translatable fields merge per locale; tenant databases never share presence
- In English, Romanian, Russian and German
