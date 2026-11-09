# Security policy

Filament Co-Edit lets several people edit one record at the same time: it shows who is on a record and which field they are in, and merges a save with what the others saved meanwhile. Authorisation stays with Filament and your policies; the plugin's job is to never widen what a person can see or write through its own routes, Livewire methods and merge, and to never show one tenant's people to another. Please report anything in that area privately so a fix can ship before details become public.

## Supported versions

Security fixes are released for the latest minor version of each supported release line.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |

## Reporting a vulnerability

Email **security@packstub.dev**. Do not open a public GitHub issue, discussion, or pull request for a security problem.

Include what you can: the package version (`composer show packstub/filament-coedit`), the Filament version, whether the record is edited on a page (`InteractsWithCoEdit`) or in a modal (`EditAction::make()->coEdit()`), the cache store presence runs on, and a minimal reproduction.

What to expect: acknowledgment within 48 hours from a human, updates while we validate and fix, and coordinated disclosure with credit in the release notes unless you prefer otherwise.

## Scope

Highest priority:

- **Presence across tenants or records**: seeing who is on a record you may not open, or one tenant's people on another tenant's record with the same key (rooms include the database name; the browser only ever sees a keyed hash of a room).
- **The merge writing what the form does not edit**: a merge or "Get their changes" writing an attribute that is not a field of the form, an ignored field's newer value, or a value for a related record the field would not offer.
- **Authorisation**: a browser-callable method (`save`, `coeditPull`, `heartbeat`, `leave`, `acknowledge`, the conflict dialog) doing something for a person Filament would refuse, or the save notice naming a field or a person it should not.
- **Rendering**: a user's name, a field label or a field value reaching the page, a notification or the conflict dialog as HTML.

Also in scope: the traits, the `Presence` Livewire component, the leave route (`filament-coedit/leave`, signed), `PresenceColumn`, the three events and the published views, config and translations.

Out of scope: vulnerabilities in Laravel, Filament or Livewire themselves (report upstream; we still ship a mitigation when the exposure is specific to this package), issues that need an already-compromised account or server, and missing hardening in your own application.

## Hardening guidance for integrators

- **Your policy is the control.** Co-Edit asks the resource's `canEdit()` again before a page pulls the current record, and Filament's own save authorises as usual. A view page with `HasPresence` shows presence to anyone who can open it; a person whose access is revoked keeps seeing who is on the record until their page reloads.
- **Keep the room getters `protected`.** `getCoEditRoom()`, `getCoEditMode()`, `getCoEditIgnoredFields()` and `getCoEditTranslatableAttributes()` are protected because a public method on a Livewire page can be called from the browser, and the room names the database. Overrides should be protected too.
- **Narrow relationship selects.** The conflict dialog names related records through the select's own relationship query, so `modifyQueryUsing()` (or a global scope) on a multi-tenant select also decides which names the dialog may show.
- **Share one cache store per installation.** Presence lives in the configured cache store; every server must share it, and with a cache scoped per tenant set `leave_middleware` so the leave beacon reaches the right tenant.
- **Field labels travel with a save.** "Ana saved this record" lists the labels of the fields she changed to everyone on the record, including fields a narrower form leaves out. Labels only, never values.
