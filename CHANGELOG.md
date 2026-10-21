# Changelog

All notable changes to `packstub/filament-coedit` are documented in this file.

## Unreleased

First release.

- `InteractsWithCoEdit` for edit pages: presence in the header and a save that merges. The form as loaded is the base; on save the record is read again and filled through the same form, fields only the other person changed keep their value (and a toast names them), fields only you changed take yours, and fields you both changed open a dialog with **Keep mine** / **Use Ana's** per field. A save landing while the dialog is open is merged again before writing.
- Value comparison that ignores form noise: numeric strings and numbers, `''`/`null`/`[]`, booleans, backed enums, date and datetime formats, and the fresh UUID keys of repeater and file upload items; real differences (zero vs empty, leading zeros, item order) still count.
- Only fields on the form are merged; timestamps and attributes the form does not edit are never written or reported.
- `HasPresence` for view pages.
- Field-level presence: a coloured outline and name tag on the field each person is in, found from `wire:model` or `$entangle()`, put back after Livewire morphs the form.
- "Ana saved this record" notice with the changed fields, **Get their changes** (pulls them into the open form without saving) and **Dismiss**; it closes as soon as the page has merged that save.
- `PresenceColumn` for tables.
- Cache-backed presence (any store with locks), heartbeats that never re-render, a signed `sendBeacon` so closing tabs leave at once, one entry per person across tabs, colours stable per user.
- English, Romanian, Russian and German.
