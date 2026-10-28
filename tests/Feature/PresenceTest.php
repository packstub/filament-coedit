<?php

use Filament\Facades\Filament;
use Filament\Support\ArrayRecord;
use Filament\Tables\Table;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Tables\PresenceColumn;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
use Packstub\CoEdit\Tests\Fixtures\ListArticles;
use Packstub\CoEdit\Tests\Fixtures\ViewArticle;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->article = article();
    $this->room = PresenceStore::room($this->article);
});

function presenceAs($user, string $room, string $mode = 'editing', int $version = 0)
{
    test()->actingAs($user);

    return livewire(Presence::class, ['room' => $room, 'mode' => $mode, 'version' => $version]);
}

it('puts the avatars in the header of an edit page', function (): void {
    $this->actingAs($this->ion);

    livewire(EditArticle::class, ['record' => $this->article->getKey()])
        ->assertSeeLivewire(Presence::class);
});

it('puts them on view pages too, as viewing', function (): void {
    $this->actingAs($this->ion);

    livewire(ViewArticle::class, ['record' => $this->article->getKey()])
        ->assertSeeLivewire(Presence::class);
});

it('stays off pages that do not use the traits', function (): void {
    $this->actingAs($this->ion);

    livewire(ListArticles::class)->assertDontSeeLivewire(Presence::class);
});

it('shows each person the others on the record and the field they are in', function (): void {
    presenceAs($this->ana, $this->room)->call('heartbeat', 'data.title');
    $ion = presenceAs($this->ion, $this->room);

    $members = $ion->instance()->snapshot()['members'];

    expect($members)->toHaveCount(1)
        ->and($members[0])->toMatchArray(['name' => 'Ana', 'mode' => 'editing', 'fields' => ['data.title']]);
});

it('ignores field reports from viewers and anything that is not a state path', function (string $mode, mixed $field): void {
    presenceAs($this->ana, $this->room, $mode)->call('heartbeat', $field);

    expect(CoEdit::store()->members($this->room)[0]['fields'])->toBe([]);
})->with([
    'viewer' => ['viewing', 'data.title'],
    'markup' => ['editing', '<img src=x onerror=alert(1)>'],
    'array' => ['editing', ['data.title']],
    'too long' => ['editing', str_repeat('a', 201)],
]);

it('keeps a modal\'s fields out of the page\'s room and the page\'s out of the modal\'s', function (): void {
    // A modal on the page edits another record (a relation manager row): its fields
    // share state paths with the same modal on any other record.
    test()->actingAs($this->ana);
    livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing'])->call('heartbeat', 'mountedActions.0.data.title');

    expect(CoEdit::store()->members($this->room)[0]['fields'])->toBe([]);

    test()->actingAs($this->ion);
    livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing', 'context' => 'modal'])->call('heartbeat', 'data.title');
    livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing', 'context' => 'modal'])->call('heartbeat', 'mountedActions.0.data.status');

    expect(collect(CoEdit::store()->members($this->room))->firstWhere('name', 'Ion')['fields'])->toBe(['mountedActions.0.data.status']);
});

it('offers to get their changes on an edit page, not in a modal that cannot be refilled', function (): void {
    CoEdit::store()->recordSave($this->room, CoEdit::who($this->ana), ['Title']);

    presenceAs($this->ion, $this->room)->assertSee('Get their changes');

    livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing', 'context' => 'modal'])
        ->assertDontSee('Get their changes')
        ->assertSee('save again to keep yours');
});

it('tells the browser when to stop reporting in, so an unattended page lets the session expire', function (): void {
    presenceAs($this->ion, $this->room)->assertSeeHtml('\\u0022idle\\u0022:600000');

});

it('never pauses when idle is off', function (mixed $idle): void {
    config(['filament-coedit.idle' => $idle]);

    presenceAs($this->ion, $this->room)->assertSeeHtml('\\u0022idle\\u0022:null');
})->with(['null' => [null], 'zero' => [0], 'false' => [false], 'empty env' => ['']]);

it('lets a page\'s merge acknowledge only its own record\'s save', function (): void {
    $other = PresenceStore::room(article());
    CoEdit::store()->recordSave($other, CoEdit::who($this->ana), ['Title']);
    $save = CoEdit::store()->recordSave($this->room, CoEdit::who($this->ana), ['Title']);

    // A modal on the page, open on another record, hears the page's event too.
    presenceAs($this->ion, $other)
        ->dispatch('coedit-acknowledged', version: $save['version'], room: PresenceStore::opaque($this->room))
        ->assertSet('version', 0);

    presenceAs($this->ion, $this->room)
        ->dispatch('coedit-acknowledged', version: $save['version'], room: PresenceStore::opaque($this->room))
        ->assertSet('version', $save['version']);
});

it('cannot be pointed at another room from the browser', function (): void {
    presenceAs($this->ion, $this->room)->set('room', 'App\\Models\\User:1');
})->throws(CannotUpdateLockedPropertyException::class);

it('drops a tab that leaves', function (): void {
    presenceAs($this->ana, $this->room)->call('leave');

    expect(CoEdit::store()->members($this->room))->toBe([]);
});

it('tells the others who saved and what changed, until they acknowledge it', function (): void {
    $ionPresence = presenceAs($this->ion, $this->room);

    $this->actingAs($this->ana);
    livewire(EditArticle::class, ['record' => $this->article->getKey()])
        ->fillForm(['status' => 'review', 'price' => 20])
        ->call('save');

    $this->actingAs($this->ion);
    expect($ionPresence->instance()->snapshot()['save'])
        ->toMatchArray(['name' => 'Ana', 'fields' => ['Status', 'Price']]);

    $ionPresence->call('acknowledge', CoEdit::store()->lastSave($this->room)['version']);

    expect($ionPresence->instance()->snapshot()['save'])->toBeNull();
});

it('does not announce your own save to you', function (): void {
    $ionPresence = presenceAs($this->ion, $this->room);

    livewire(EditArticle::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => 'Mine'])
        ->call('save');

    expect($ionPresence->instance()->snapshot()['save'])->toBeNull();
});

it('lists who is on each row of a table', function (): void {
    presenceAs($this->ana, $this->room);

    $this->actingAs($this->ion);

    livewire(ListArticles::class)
        ->assertSeeHtml('title="Ana · editing"');
});

it('reads the presence of a whole page of rows at once', function (): void {
    $second = article(['title' => 'Summer launch']);
    article(['title' => 'Winter launch']);

    presenceAs($this->ana, $this->room);
    presenceAs($this->ana, PresenceStore::room($second), mode: 'viewing');

    $reads = ['one' => 0, 'many' => 0];
    Event::listen(RetrievingKey::class, function ($event) use (&$reads): void {
        str_starts_with($event->key, 'filament-coedit:') && ! str_ends_with($event->key, ':save') && $reads['one']++;
    });
    Event::listen(RetrievingManyKeys::class, function () use (&$reads): void {
        $reads['many']++;
    });

    $this->actingAs($this->ion);

    livewire(ListArticles::class)
        ->assertSeeHtml('title="Ana · editing"')
        ->assertSeeHtml('title="Ana · viewing"');

    expect($reads)->toBe(['one' => 0, 'many' => 1]);
});

it('lets a closing tab leave through a signed beacon, and only that tab', function (): void {
    presenceAs($this->ana, $this->room);
    $ion = presenceAs($this->ion, $this->room);
    $tab = $ion->get('tab');

    // The URL the page hands the browser, with the opaque room the component holds.
    $url = Presence::leaveUrl($ion->get('room'), $tab);

    $this->post($url)->assertNoContent();
    $this->post(str_replace('tab='.$tab, 'tab=other', $url))->assertForbidden();

    expect(array_column(CoEdit::store()->members($this->room), 'name'))->toBe(['Ana']);
});

it('sends the beacon to an app in a subdirectory, and the signature holds there', function (): void {
    $server = ['SCRIPT_NAME' => '/office/index.php', 'SCRIPT_FILENAME' => '/var/www/office/index.php'];
    $request = Request::create('https://acme.test/office/admin', server: $server);
    app()->instance('request', $request);
    URL::setRequest($request);

    $url = Presence::leaveUrl(PresenceStore::opaque($this->room), 'tab-a');

    expect($url)->toStartWith('/office/filament-coedit/leave?')
        ->and(URL::hasValidSignature(Request::create('https://acme.test'.$url, 'POST', server: $server), absolute: false))->toBeTrue();
});

it('never tells the browser which user model or id the others are', function (): void {
    presenceAs($this->ana, $this->room)->call('heartbeat');

    $member = presenceAs($this->ion, $this->room)->instance()->snapshot()['members'][0];

    expect($member['name'])->toBe('Ana')
        ->and($member['user'])->toBe(PresenceStore::opaque(CoEdit::who($this->ana)['user']))
        ->and($member['user'])->not->toContain('User');
});

it('keeps presence in the configured cache store', function (): void {
    config(['cache.stores.coedit' => ['driver' => 'array'], 'filament-coedit.cache_store' => 'coedit']);

    presenceAs($this->ana, $this->room)->call('heartbeat');

    expect(Cache::store('coedit')->get('filament-coedit:'.substr(PresenceStore::opaque($this->room), 1)))->toHaveCount(1)
        ->and(Cache::store('array')->get('filament-coedit:'.substr(PresenceStore::opaque($this->room), 1)))->toBeNull();
});

it('leaves a row that is not a model empty', function (): void {
    $this->actingAs($this->ion);
    $column = PresenceColumn::make('presence')->table(Table::make(new ListArticles));

    expect($column->record([ArrayRecord::getKeyName() => 'row-1', 'title' => 'An array row'])->getState())->toBeEmpty();
});
