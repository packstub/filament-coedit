<?php

use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->article = article();
});

function editAs($user, $article)
{
    test()->actingAs($user);

    return livewire(EditArticle::class, ['record' => $article->getKey()]);
}

function anaSaves($article, array $changes): void
{
    editAs(test()->ana, $article)->fillForm($changes)->call('save')->assertHasNoFormErrors();
}

it('saves as usual when nobody else touched the record', function (): void {
    editAs($this->ion, $this->article)
        ->fillForm(['title' => 'Autumn launch'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->title)->toBe('Autumn launch')
        ->and(CoEdit::store()->lastSave(PresenceStore::room($this->article)))
        ->toMatchArray(['name' => 'Ion', 'fields' => ['Title']]);
});

it('keeps a colleague\'s change to a field you did not touch', function (): void {
    $ion = editAs($this->ion, $this->article);

    anaSaves($this->article, ['status' => 'review', 'price' => 12]);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertActionNotMounted('coeditResolve')
        ->assertNotified('Kept Ana\'s changes');

    expect($this->article->fresh())
        ->title->toBe('Autumn launch')
        ->status->toBe('review')
        ->price->toBe('12.00');
});

it('asks per field when both changed the same one, and saves "keep mine"', function (): void {
    $ion = editAs($this->ion, $this->article);

    anaSaves($this->article, ['title' => 'Ana\'s title', 'status' => 'review']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion\'s title'])
        ->call('save')
        ->assertActionMounted('coeditResolve')
        ->assertMountedActionModalSee(['Ana saved this record while you were editing', 'Ion\'s title', 'Ana\'s title']);

    expect($this->article->fresh()->title)->toBe('Ana\'s title');

    $ion->setActionData(['choices' => ['title' => 'mine']])->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->article->fresh())
        ->title->toBe('Ion\'s title')
        ->status->toBe('review');
});

it('saves "use theirs" from the dialog', function (): void {
    $ion = editAs($this->ion, $this->article);

    anaSaves($this->article, ['title' => 'Ana\'s title']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion\'s title', 'price' => 99])
        ->call('save')
        ->assertActionMounted('coeditResolve')
        ->setActionData(['choices' => ['title' => 'theirs']])->callMountedAction();

    expect($this->article->fresh())
        ->title->toBe('Ana\'s title')
        ->price->toBe('99.00');
});

it('merges again when a third save lands while the dialog is open', function (): void {
    $ion = editAs($this->ion, $this->article);

    anaSaves($this->article, ['title' => 'Ana\'s title']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion\'s title'])->call('save')->assertActionMounted('coeditResolve');

    anaSaves($this->article->fresh(), ['status' => 'published']);

    $this->actingAs($this->ion);
    $ion->setActionData(['choices' => ['title' => 'mine']])->callMountedAction();

    expect($this->article->fresh())
        ->title->toBe('Ion\'s title')
        ->status->toBe('published');
});

it('ignores attributes the form does not edit', function (): void {
    $ion = editAs($this->ion, $this->article);

    $this->article->forceFill(['secret' => 'rotated'])->save();

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])
        ->call('save')
        ->assertActionNotMounted('coeditResolve')
        ->assertNotNotified('Kept Someone\'s changes');

    expect($this->article->fresh())->secret->toBe('rotated')->title->toBe('Autumn launch');
});

it('does not see a repeater refilled with new item keys as a change', function (): void {
    $ion = editAs($this->ion, $this->article);

    anaSaves($this->article, ['title' => 'Ana\'s title']);

    $this->actingAs($this->ion);
    $ion->fillForm(['price' => 15])
        ->call('save')
        ->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh())
        ->title->toBe('Ana\'s title')
        ->tags->toBe(['news'])
        ->price->toBe('15.00');
});

it('merges a repeater someone else edited', function (): void {
    $ion = editAs($this->ion, $this->article);

    $this->article->update(['tags' => ['news', 'launch']]);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->tags)->toBe(['news', 'launch']);
});

it('never recreates a record someone deleted', function (): void {
    $ion = editAs($this->ion, $this->article);

    $this->article->delete();

    // Livewire cannot restore a deleted record, so the request ends before any save runs.
    $this->actingAs($this->ion);
    try {
        $ion->fillForm(['title' => 'Autumn launch'])->call('save');
    } catch (Throwable) {
        // A 404 in production, an exception with app.debug on: either way nothing is saved.
    }

    expect($this->article->fresh())->toBeNull();
});

it('brings their changes into the open form without saving', function (): void {
    $ion = editAs($this->ion, $this->article);
    $ion->fillForm(['title' => 'Draft of mine']);

    anaSaves($this->article, ['status' => 'review', 'title' => 'Ana\'s title']);

    $this->actingAs($this->ion);
    $ion->call('coeditPull')
        ->assertSet('data.status', 'review')
        ->assertSet('data.title', 'Draft of mine')
        ->assertNotified('Form updated');

    expect($this->article->fresh()->title)->toBe('Ana\'s title');

    // The title is still contested, so saving asks.
    $ion->call('save')->assertActionMounted('coeditResolve');
});

it('pulls when the save notice asks for this record, and ignores another record\'s notice', function (): void {
    $ion = editAs($this->ion, $this->article);

    anaSaves($this->article, ['status' => 'review']);

    $this->actingAs($this->ion);
    $ion->dispatch('coedit-pull', room: PresenceStore::opaque('other-room'))
        ->assertSet('data.status', 'draft')
        ->assertNotNotified('Form updated');

    $ion->dispatch('coedit-pull', room: PresenceStore::opaque(PresenceStore::room($this->article)))
        ->assertSet('data.status', 'review')
        ->assertNotified('Form updated');
});

it('keeps the merge base out of reach of the browser', function (): void {
    editAs($this->ion, $this->article)
        ->set('coeditBase', ['title' => 'forged']);
})->throws(CannotUpdateLockedPropertyException::class);
