<?php

use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Tests\Fixtures\Article;

afterEach(function (): void {
    PresenceStore::resolveRoomUsing(null);
});

it('keeps the same id in two tenant databases apart', function (): void {
    // Laravel connects lazily: naming the database is enough, nothing is opened.
    config(['database.connections.tenant_b' => ['driver' => 'sqlite', 'database' => '/tmp/tenant-b.sqlite', 'prefix' => '']]);

    $a = article();
    $b = (new Article)->setConnection('tenant_b')->forceFill(['id' => $a->getKey()]);

    expect(PresenceStore::room($a))->not->toBe(PresenceStore::room($b))
        ->and(PresenceStore::room($b))->toBe('/tmp/tenant-b.sqlite|'.$a->getMorphClass().':'.$a->getKey());

    CoEdit::store()->touch(PresenceStore::room($a), 'tab', ['user' => '1', 'name' => 'Ana', 'avatar' => null], 'editing');

    expect(CoEdit::store()->members(PresenceStore::room($b)))->toBe([]);
});

it('lets the app decide what a room is', function (): void {
    PresenceStore::resolveRoomUsing(fn ($record): string => 'team-7:'.$record->getKey());

    expect(PresenceStore::room(article()))->toBe('team-7:1');
});

it('names the same record the same room every time', function (): void {
    $article = article();

    expect(PresenceStore::room($article))->toBe(PresenceStore::room(Article::query()->find($article->getKey())));
});
