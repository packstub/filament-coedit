# packstub/filament-coedit

Free (MIT) Filament v4/v5 plugin: edit records together without locks. Presence (who is on the record, which
field they are in) through a cache-backed heartbeat, and a save that three-way merges the form against the
database, field by field, opening a per-field dialog only for real conflicts. No websockets, no migrations, no
panel plugin: a trait on the page is the whole install.

## Commands

```bash
composer test               # Pest suite (unit: merge + store; feature: two users on real EditRecord pages)
composer test:filter <name>
composer lint               # Pint
composer serve              # workbench panel: Products (edit pages) + Suppliers (modal edits); ion@/ana@/mihai@example.com, password "password"
node scripts/screenshots.mjs  # three-person browser rig against the workbench → docs/images, every step asserted
```

The rig needs the workbench on `127.0.0.1:8765` with workers (`PHP_CLI_SERVER_WORKERS=8 vendor/bin/testbench
serve --port=8765`; the built-in server is single-threaded and three polling browsers stall it), uses
puppeteer-core from `demos/packstub-demo/node_modules`, and logs in three times per run: Filament throttles
logins to 5 a minute per IP, so wait a minute between runs.

## Layout

- `src/Merge/ThreeWayMerge.php`: pure functions. `merge(base, mine, theirs)` → `MergeResult` (data, taken,
  conflicts); `normalize()` is the only place that decides what counts as "the same value" (numeric strings,
  ''/null/[], bools, enums, dates, UUID-keyed repeater/file items, key order). Add a case here, with a dataset row
  in `tests/Unit/ThreeWayMergeTest.php`, never in the trait.
- `src/Presence/PresenceStore.php`: the cache. One entry per room (`morphClass:key`) holding tabs keyed by a
  random tab id, read-modify-write under `Cache::lock`; grouped per user on read. `recordSave()` keeps a
  versioned "last save" per room (who, when, field labels).
- `src/Concerns/InteractsWithCoEdit.php` (edit pages) / `HasPresence.php` (view pages). The trait overrides
  `save()` and calls `parent::save()`; the merge base is the form data at mount (`mountInteractsWithCoEdit`),
  limited to top-level field names. "Theirs" is computed by `$record->refresh()`, `form->fill()` through
  `mutateFormDataBeforeFill()`, capture, then restoring `$this->data`, so all three versions share the form's
  representation. `coeditAfterSave()` runs from Filament's `RecordSaved` event (dispatched as a class-name string
  with a `[record, data, page]` payload, not an object).
- `src/Actions/CoEditAction.php`: `EditAction::make()->coEdit()` (macro registered in the service provider).
  Fingerprints of the filled form live in the modal's raw state under `__coedit` (client-visible, which is fine:
  it only steers the person's own merge; authorisation is Filament's). The modal's form is found through
  `getMountedActionSchemaName()` + `getSchema()`: an injected `$schema` in an action closure is the schema the
  action sits in (null for table actions), not the modal. Taken fields are unset from the action data (the
  refreshed record keeps theirs); a conflict halts once with a notice.
- `src/Support/FormFields.php`: field names (top-level), labels, display values (option labels, relationship
  labels by key, Yes/No, plain text from HTML) shared by the page trait and the action.
- `src/Events/{ChangesMerged,ConflictDetected,ConflictResolved}.php`.
- `src/Livewire/Presence.php` + `resources/views/presence.blade.php`: rendered once through the
  `PAGE_HEADER_ACTIONS_BEFORE` render hook (service provider, `Livewire::current()`); after that every call is
  `#[Renderless]` and Alpine draws the JSON. Field markers are found from `wire:model` or `$entangle('…')` inside
  each `[data-field-wrapper]`. Closing tabs leave through a signed `sendBeacon` to `filament-coedit/leave`.
- `src/Tables/PresenceColumn.php`, `resources/dist/coedit.css` (Filament asset; `filament:assets` after edits in
  a consuming app), `resources/lang/{en,ro,ru,de}`, `config/filament-coedit.php`.
- `workbench/` + `testbench.yaml`: the demo panel (Products) used by `composer serve` and the rig.

## Conventions

- Every public property on the trait and the component is `#[Locked]`; every public method is browser-callable
  (`save`, `coeditPull`, `heartbeat`, `leave`, `acknowledge`): check input there (`sanitizeField()`).
- Only top-level form fields take part in a merge; never compare or write attributes the form does not edit.
- `coeditTheirs` holds only the conflicting fields, never the whole record.
- A heartbeat never renders and never touches the database.
- A conflict found while the dialog's own submit is saving (`$coeditResolving`) refills the mounted dialog and
  throws `Halt`: Filament unmounts an action that "replaced" itself with the same name.
- Rooms include `getConnection()->getDatabaseName()` (database-per-tenant apps); `PresenceStore::resolveRoomUsing()`.
- Filament floor is 4.13.3 / 5.8.3 (earlier versions are blocked by Composer's security advisories); CI runs
  4.13.3, 4.*, 5.8.3 and 5.*.
- Every change needs a test; `CHANGELOG.md` stays current. Strings in `resources/lang/{en,ro,ru,de}`.
