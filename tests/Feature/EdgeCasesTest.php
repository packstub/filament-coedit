<?php

use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Support\FormFields;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
use Packstub\CoEdit\Tests\Fixtures\ListArticles;
use Packstub\CoEdit\Tests\Fixtures\NamedUser;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->article = article();
    $this->room = PresenceStore::room($this->article);
});

function editPageAs($user, $article)
{
    test()->actingAs($user);

    return livewire(EditArticle::class, ['record' => $article->getKey()]);
}

it('asks again when a newer save changes the same field while the dialog is open', function (): void {
    $ion = editPageAs($this->ion, $this->article);
    editPageAs($this->ana, $this->article)->fillForm(['title' => 'Ana 1'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion'])->call('save')->assertActionMounted('coeditResolve');

    editPageAs($this->ana, $this->article->fresh())->fillForm(['title' => 'Ana 2'])->call('save');

    $this->actingAs($this->ion);
    $ion->setActionData(['choices' => ['title' => 'mine']])->callMountedAction();

    expect($this->article->fresh()->title)->toBe('Ana 2');
    $ion->assertActionMounted('coeditResolve')->assertSet('coeditTheirs', ['title' => 'Ana 2']);

    $ion->setActionData(['choices' => ['title' => 'mine']])->callMountedAction();

    expect($this->article->fresh()->title)->toBe('Ion');
});

it('adds a field to the open dialog when a newer save conflicts on it', function (): void {
    $ion = editPageAs($this->ion, $this->article);
    editPageAs($this->ana, $this->article)->fillForm(['title' => 'Ana'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion', 'price' => 30])->call('save')->assertActionMounted('coeditResolve');

    editPageAs($this->ana, $this->article->fresh())->fillForm(['price' => 40])->call('save');

    $this->actingAs($this->ion);
    $ion->setActionData(['choices' => ['title' => 'mine']])->callMountedAction()
        ->assertActionMounted('coeditResolve')
        ->assertSet('coeditConflicts', ['price']);

    expect($this->article->fresh())->title->toBe('Ana')->price->toBe('40.00');

    $ion->setActionData(['choices' => ['price' => 'theirs']])->callMountedAction()->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh())->title->toBe('Ion')->price->toBe('40.00');
});

it('names "Someone" when the change did not come through Co-Edit', function (): void {
    $ion = editPageAs($this->ion, $this->article);

    $this->article->update(['title' => 'From the API']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion'])
        ->call('save')
        ->assertMountedActionModalSee('Someone saved this record while you were editing');
});

it('does not record a save that changed nothing', function (): void {
    editPageAs($this->ion, $this->article)->call('save')->assertHasNoFormErrors();

    expect(CoEdit::store()->lastSave($this->room))->toBeNull();
});

it('treats a pull with nothing new as a quiet no-op', function (): void {
    editPageAs($this->ion, $this->article)
        ->fillForm(['title' => 'Draft'])
        ->call('coeditPull')
        ->assertSet('data.title', 'Draft')
        ->assertNotNotified('Form updated');
});

it('counts saves so each page only hears about newer ones', function (): void {
    $ion = editPageAs($this->ion, $this->article);
    $ion->fillForm(['title' => 'One'])->call('save');
    $ion->fillForm(['title' => 'Two'])->call('save');

    expect(CoEdit::store()->lastSave($this->room)['version'])->toBe(2)
        ->and($ion->get('coeditVersion'))->toBe(2);
});

it('shows people by their panel name and avatar', function (): void {
    config(['auth.providers.users.model' => NamedUser::class]);
    $named = NamedUser::query()->create(['name' => 'Ana', 'email' => 'named@example.com', 'password' => 'x']);

    $this->actingAs($named);
    livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing']);

    expect(CoEdit::store()->members($this->room)[0])->toMatchArray([
        'name' => 'Dr. Ana',
        'avatar' => 'https://example.com/avatars/'.$named->getKey().'.png',
    ]);
});

it('stops tracking fields when field presence is off', function (): void {
    config(['filament-coedit.track_fields' => false]);

    $this->actingAs($this->ana);
    livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing'])
        ->assertViewHas('config', fn (array $config): bool => $config['trackFields'] === false)
        ->call('heartbeat', 'data.title');

    expect(CoEdit::store()->members($this->room)[0]['fields'])->toBe([]);
});

it('passes the heartbeat interval and a signed leave URL to the browser', function (): void {
    config(['filament-coedit.heartbeat' => 7]);

    $this->actingAs($this->ion);
    livewire(Presence::class, ['room' => $this->room])
        ->assertViewHas('config', fn (array $config): bool => $config['heartbeat'] === 7000
            && str_starts_with($config['leave'], '/filament-coedit/leave?room=')
            && str_contains($config['leave'], 'signature='));
});

it('marks the people who are only viewing as viewing', function (): void {
    $this->actingAs($this->ana);
    livewire(Presence::class, ['room' => $this->room, 'mode' => 'something-else']);

    expect(CoEdit::store()->members($this->room)[0]['mode'])->toBe('viewing');
});

it('keeps someone who is still sending heartbeats and drops them after the timeout', function (): void {
    config(['filament-coedit.timeout' => 20]);

    $this->actingAs($this->ana);
    $ana = livewire(Presence::class, ['room' => $this->room, 'mode' => 'editing']);

    foreach (range(1, 4) as $beat) {
        $this->travel(10)->seconds();
        $ana->call('heartbeat');
    }

    expect(CoEdit::store()->members($this->room))->toHaveCount(1);

    $this->travel(21)->seconds();

    expect(CoEdit::store()->members($this->room))->toBe([]);
});

it('only acknowledges saves that exist', function (): void {
    CoEdit::store()->recordSave($this->room, ['user' => '2', 'name' => 'Ana', 'avatar' => null], ['Title']);

    $this->actingAs($this->ion);
    $presence = livewire(Presence::class, ['room' => $this->room])->call('acknowledge', 999);

    expect($presence->get('version'))->toBe(1);
});

it('shows a "+n" chip in the table when more than three people are on a row', function (): void {
    foreach (['Ana', 'Mihai', 'Elena', 'Radu', 'Ioana'] as $i => $name) {
        CoEdit::store()->touch($this->room, "tab-{$i}", ['user' => (string) (100 + $i), 'name' => $name, 'avatar' => null], 'viewing');
    }

    $this->actingAs($this->ion);
    livewire(ListArticles::class)
        ->assertSeeHtml('+2')
        ->assertSeeHtml('title="Radu, Ioana"');
});

it('falls back to a headline when a field has no label', function (): void {
    $schema = Schema::make(livewire(ListArticles::class)->instance())->components([]);

    expect(FormFields::label($schema, 'shipping_address'))->toBe('Shipping Address')
        ->and(FormFields::display($schema, 'flag', true))->toBe('Yes')
        ->and(FormFields::display($schema, 'flag', false))->toBe('No')
        ->and(FormFields::display($schema, 'note', '<p>Hello&nbsp;<b>world</b></p>'))->toBe('Hello world')
        ->and(FormFields::display($schema, 'note', ''))->toBe('(empty)')
        ->and(FormFields::display($schema, 'tags', ['a', 'b']))->toBe('a, b');
});
