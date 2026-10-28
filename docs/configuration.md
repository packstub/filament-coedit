# Configuration

Optional. Publish it with `php artisan vendor:publish --tag=filament-coedit-config`.

| Key | Default | What it does |
| --- | --- | --- |
| `cache_store` | default store | Where presence lives, also set by `FILAMENT_COEDIT_CACHE_STORE`. Every server must share it, so in production use a shared store such as Redis or the database. |
| `heartbeat` | `4` | Seconds between heartbeats. A page also reports at once when its user moves to another field. |
| `timeout` | `20` | Seconds without a heartbeat before someone drops off. A closing tab leaves immediately. Never less than two heartbeats plus a second. |
| `idle` | `600` | Seconds without a keypress, click or pointer move before a page stops reporting in and its person drops off; the next one brings them back. Heartbeats keep the session alive, so this lets an unattended page sign out as it would without Co-Edit. `null` never pauses. |
| `track_fields` | `true` | Show which field each person is in. Off, the avatars stay and the browser never reports focus. |
| `remember_saves_for` | `3600` | Seconds that "Ana saved this record" is remembered for the people still on the record. The merge itself always compares against the database. |
| `leave_middleware` | `[]` | Middleware for the route a closing tab leaves through, outside your panel. With a cache your tenancy scopes per tenant, add the middleware that identifies the tenant (stancl/tenancy: `InitializeTenancyByDomain`), or people drop off after `timeout` instead of at once. The route serves every panel and runs this before the signature check, so a middleware that fails on your central domain logs an error for a central panel's beacons; use one that passes central requests through. |
