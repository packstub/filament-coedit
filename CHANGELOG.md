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
- `EditAction::make()->coEdit()` for modal edits (relation managers, simple resources, table edit actions): the form is fingerprinted into the modal's state when it opens; on submit, fields only the other person changed are left out of the update, and a conflict stops the first submit with their values in a notice (submitting again keeps yours). Avatars and field markers in the modal; `ignore:` and `presence:` arguments; wraps the action's existing `afterFormFilled()`, `before()` and `after()` callbacks.
- `getCoEditIgnoredFields()` to leave fields out of the merge (they save as in plain Filament).
- Events: `ChangesMerged`, `ConflictDetected`, `ConflictResolved`.
- Rooms include the record's database, so the same id in two tenant databases never shares presence; `PresenceStore::resolveRoomUsing()` to decide otherwise.
- When a newer save conflicts while the dialog is submitting, the dialog stays open with the new conflicts instead of closing.
- Relationship selects show related records by name in the dialog, also when their options are searched rather than preloaded; `&nbsp;` and other entities read as plain text.
- `track_fields` config option; a modal's autofocused field is reported as soon as presence starts; a presence component that goes away (a modal closing) leaves the record at once.
- `coeditMergeBeforeSave()` for pages that override `save()`.
- `PresenceColumn` for tables.
- Cache-backed presence (any store with locks), heartbeats that never re-render, a signed `sendBeacon` so closing tabs leave at once, one entry per person across tabs, colours stable per user.
- English, Romanian, Russian and German.
- Saves of the same record run one at a time, on edit pages and in modal edits, under a lock in the cache store: two people pressing Save in the same instant are merged one after the other instead of overwriting each other. A save that waits more than 10 seconds says so and writes nothing. `CoEdit::store()->whileSaving()` for pages with their own `save()`.
- Only the page's own save moves the merge base: a header action that saves another record (a `CreateAction`, which Filament also reports as a save of the page) leaves your unsaved edits as yours.
- "Ion saved this record" lists only the fields Ion changed, not the ones his save kept from someone else.
- The save announcement cannot be triggered from the browser, and the conflict dialog opened without a conflict does nothing.
- "Get their changes" on a record deleted meanwhile says so instead of failing.
- The others hear about a save once it is committed, so nobody pulls the version before it.
- The conflict dialog names related records through the select's own relationship query (`modifyQueryUsing`), so a key sent from the browser never reveals a record the field would not offer.
- `PresenceColumn` reads the whole page of rows from the cache at once instead of once per row; a heartbeat reads the room once instead of twice.
- A presence component that goes away (a modal closing, SPA navigation) stops for good: a heartbeat still in flight cannot bring the tab back, and its timers and Livewire hook are released. A page restored from the back-forward cache reports in again.
- Save versions are a clock, not a counter, so a save after the last one was forgotten (`remember_saves_for`) is still announced to pages open since before.
- People are told apart by user model and key, so admin #1 and customer #1 on two panels are two people.
- `timeout` is never shorter than two heartbeats plus a second.
- Requires Filament 4.13.3+ or 5.8.3+, the first releases past the published multi-factor authentication advisories.
