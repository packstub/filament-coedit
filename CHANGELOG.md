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
- "Their" version is filled the way the page fills itself (`fillForm()`, with `beforeFill`/`afterFill` and any override), so a value a hook sets is never mistaken for someone else's change and reverted.
- Value comparison is stricter where it used to be too lenient (and `.5` still equals `0.5`): a text that starts with a date-time is compared in full, only plain decimals count as numbers (`+40123`, `1e3`, `12 ` stay text), long numeric ids are compared digit by digit, and invalid UTF-8 is compared byte for byte.
- Names are escaped in notifications.
- Works under `Relation::enforceMorphMap()` whether or not the user model is mapped.
- After your own save the merge starts from what the save wrote, read back through the form, while the form as you left it still counts as untouched: a value stored differently from how you typed it (`mutateFormDataBeforeSave()`, `dehydrateStateUsing()`, a mutator) no longer comes back as "Someone's" change, never opens the dialog against yourself, and a colleague's later change to it is kept.
- Pages with their own `save()` call `coeditAfterSave()` once saved (README updated); a record deleted by the save itself no longer fails it.
- Field markers stay with their record: the page's avatars ignore the fields of a modal open on it (a relation manager row is another record), and a modal's avatars ignore the page's.
- The save notice inside a modal has no **Get their changes** button (there is nothing to refill a modal with) and says what saving does there instead.
- `idle` config option (default 600 seconds; `null`, `0` or `false` never pause): a page nobody has touched stops sending heartbeats until the next keypress or pointer move, so an unattended page lets its session expire and stops loading the server.
- A heartbeat or page render that finds the room's lock busy skips one beat instead of failing, and announcing a committed save never fails it.
- The browser sees a room as an HMAC under the app key (`PresenceStore::opaque()`), never the database name behind it (a tenant's, or an SQLite file's path), the model class or the key, and cannot confirm a guess of them.
- Merging on a page acknowledges the save notice of that record only, not of a modal open on another one.
- Merging fills their version through your page and then restores every public property of the page, not only the form: a `fillForm()` that resets page state (Filament's Translatable concern resets the active locale) no longer makes the save write your edit to the wrong locale.
- `before()` and `after()` chained after `coEdit()` no longer turn the modal merge off: the merge runs before the form is validated (relationship fields save as it is read) and the save checks that it ran. An `afterFormFilled()`, `beforeFormValidated()` or `action()` set after `coEdit()` fails loudly (reported in production) instead of saving without a merge; an `action()` given as a method name and the modal's `$schema` reach the save as before.
- Fields that take their path from a layout (a section with its own `statePath()`) or a relationship group (`Group::make()->relationship()`) take part in the merge, on edit pages and in modals; they were left out and could be overwritten.
- A save started from another action (a header "Save and publish", with or without a confirmation) opens the conflict dialog instead of stopping without a word, and stops that action, so what it does after the save does not run.
- A modal submit that merged and then stopped (a validation error, a halting `before()`) keeps their values as theirs: the next submit no longer reports a false conflict or writes them back over a newer save.
- The presence component's key no longer carries a hash of the raw room.
- "Deleted this record" no longer guesses who deleted it.
- `PresenceColumn` leaves rows that are not Eloquent models empty instead of failing.
- A conflict on an edit page checks your form first (with your `beforeValidate()`), so an invalid form shows its errors instead of opening the dialog and then failing behind it.
- `getCoEditRoom()`, `getCoEditMode()`, `getCoEditIgnoredFields()` and `renderCoEditPresence()` are protected: on a Livewire page a public method can be called from the browser, and the room names the database. Overrides should be `protected` too.
- The browser gets the others as opaque ids, never their user model and key.
- The leave beacon reaches apps installed in a subdirectory.
- Text that only looks like a number is no longer reverted: whether someone else changed a field compares their version with the base exactly (both come from the database), so a version or SKU going from `1.1` to `1.10` is their change and kept; your typed `10.5` against their `10.50` is still no conflict. A modal save announces such a change too.
- A modal's fingerprints are hashed, so its state no longer carries a copy of every field.
- Requires Filament 4.13.3+ or 5.8.3+, the first releases past the published multi-factor authentication advisories.
