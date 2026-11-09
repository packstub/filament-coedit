# Configuration

Optional. Publish it with `php artisan vendor:publish --tag=filament-coedit-config`.

| Key | Default | What it does |
| --- | --- | --- |
| `cache_store` | default store | Where presence lives. `FILAMENT_COEDIT_CACHE_STORE` sets it too. Every server must share the store, so in production use one they can all reach, such as Redis or the database. |
| `heartbeat` | `4` | Seconds between heartbeats. A page also reports at once when its user moves to another field. |
| `timeout` | `20` | Seconds without a heartbeat before someone drops off. A closing tab leaves at once. A value lower than two heartbeats plus a second is raised to that. |
| `idle` | `600` | Seconds without a keypress, click or pointer move before a page stops reporting in and its person drops off. The next keypress, click or pointer move brings them back. Heartbeats keep the session alive, so this lets an unattended page sign its user out as it would without Co-Edit. `null` never pauses. |
| `track_fields` | `true` | Show which field each person is in. When off, the avatars stay and the browser never reports focus. |
| `remember_saves_for` | `3600` | Seconds that "Ana saved this record" is remembered for the people still on the record. The merge itself always compares against the database. |
| `leave_middleware` | `[]` | Middleware for the route a closing tab leaves through, outside your panel. If your tenancy scopes the cache per tenant, add the middleware that identifies the tenant (stancl/tenancy: `InitializeTenancyByDomain`). Without it, people drop off after `timeout` instead of at once. The route serves every panel and runs this middleware before the signature check. A middleware that fails on your central domain logs an error for every beacon from a central panel, so use one that passes central requests through. |
