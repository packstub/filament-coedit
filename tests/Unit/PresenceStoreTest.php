<?php

use Illuminate\Contracts\Cache\LockTimeoutException;
use Packstub\CoEdit\Presence\PresenceStore;

function who(string $id, string $name): array
{
    return ['user' => $id, 'name' => $name, 'avatar' => null];
}

beforeEach(function (): void {
    $this->store = app(PresenceStore::class);
});

it('groups tabs per person, the most active mode winning and every field listed', function (): void {
    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'viewing');
    $this->store->touch('article:1', 'tab-b', who('1', 'Ana'), 'editing', 'data.title');
    $this->store->touch('article:1', 'tab-c', who('2', 'Ion'), 'editing', 'data.price');

    $members = $this->store->members('article:1');

    expect($members)->toHaveCount(2)
        ->and($members[0])->toMatchArray(['user' => '1', 'name' => 'Ana', 'mode' => 'editing', 'fields' => ['data.title']])
        ->and($members[0]['color'])->toBe(PresenceStore::colorFor('1'))
        ->and($members[1])->toMatchArray(['user' => '2', 'fields' => ['data.price']]);
});

it('leaves the asking person out', function (): void {
    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'editing');
    $this->store->touch('article:1', 'tab-b', who('2', 'Ion'), 'editing');

    expect(array_column($this->store->members('article:1', exceptUser: '2'), 'name'))->toBe(['Ana']);
});

it('drops someone who stopped sending heartbeats', function (): void {
    config(['filament-coedit.timeout' => 20]);

    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'editing');
    $this->travel(15)->seconds();
    $this->store->touch('article:1', 'tab-b', who('2', 'Ion'), 'editing');

    expect($this->store->members('article:1'))->toHaveCount(2);

    $this->travel(10)->seconds();

    expect(array_column($this->store->members('article:1'), 'name'))->toBe(['Ion']);
});

it('forgets a tab that leaves', function (): void {
    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'editing');
    $this->store->leave('article:1', 'tab-a');

    expect($this->store->members('article:1'))->toBe([]);
});

it('keeps rooms apart and reads many at once', function (): void {
    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'editing');
    $this->store->touch('article:2', 'tab-b', who('2', 'Ion'), 'viewing');

    $rooms = $this->store->membersOfMany(['article:1', 'article:2', 'article:3']);

    expect(array_column($rooms['article:1'], 'name'))->toBe(['Ana'])
        ->and(array_column($rooms['article:2'], 'name'))->toBe(['Ion'])
        ->and($rooms['article:3'])->toBe([]);
});

it('numbers the saves of a record', function (): void {
    $first = $this->store->recordSave('article:1', who('1', 'Ana'), ['Title']);
    $second = $this->store->recordSave('article:1', who('2', 'Ion'), ['Status', 'Price']);

    expect($second['version'])->toBeGreaterThan($first['version'])
        ->and($this->store->lastSave('article:1'))->toMatchArray(['version' => $second['version'], 'name' => 'Ion', 'fields' => ['Status', 'Price']])
        ->and($this->store->lastSave('article:2'))->toBeNull();
});

it('returns everyone else on the record from a heartbeat', function (): void {
    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'editing', 'data.title');

    expect($this->store->touch('article:1', 'tab-b', who('2', 'Ion'), 'editing'))
        ->toBe($this->store->members('article:1', exceptUser: '2'))
        ->toHaveCount(1);
});

it('lets one save of a record run at a time', function (): void {
    $release = $this->store->acquireSaveLock('article:1');

    expect(fn () => $this->store->whileSaving('article:1', fn () => 'saved', wait: 0))
        ->toThrow(LockTimeoutException::class)
        ->and($this->store->whileSaving('article:2', fn () => 'saved', wait: 0))->toBe('saved');

    $release();

    expect($this->store->whileSaving('article:1', fn () => 'saved', wait: 0))->toBe('saved');
});

it('keeps numbering saves upwards after the last one is forgotten', function (): void {
    $first = $this->store->recordSave('article:1', who('1', 'Ana'), ['Title']);

    $this->travel(config('filament-coedit.remember_saves_for') + 60)->seconds();

    expect($this->store->lastSave('article:1'))->toBeNull()
        ->and($this->store->recordSave('article:1', who('1', 'Ana'), ['Title'])['version'])->toBeGreaterThan($first['version']);
});

it('never lets the timeout fall below two heartbeats', function (): void {
    config(['filament-coedit.heartbeat' => 10, 'filament-coedit.timeout' => 5]);

    $this->store->touch('article:1', 'tab-a', who('1', 'Ana'), 'editing');
    $this->travel(15)->seconds();

    expect($this->store->members('article:1'))->toHaveCount(1);

    $this->travel(10)->seconds();

    expect($this->store->members('article:1'))->toBe([]);
});
