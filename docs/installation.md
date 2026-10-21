# Installation

```bash
composer require packstub/filament-coedit
php artisan filament:assets
```

The stylesheet is registered as a Filament asset, so `filament:assets` copies it to `public/`. Nothing goes into your theme and there is nothing to build. Presence lives in the cache and there are no migrations.

In production, use a cache store that every server shares (Redis, database, memcached). The `file` store only works on a single machine. See [Configuration](configuration.md).
