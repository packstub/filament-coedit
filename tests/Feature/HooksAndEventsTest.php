<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Packstub\CoEdit\Events\ChangesMerged;
use Packstub\CoEdit\Events\ConflictDetected;
use Packstub\CoEdit\Events\ConflictResolved;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
use Packstub\CoEdit\Tests\Fixtures\EditArticleIgnoringViews;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->article = article();
});

function openAs($user, $article, string $page = EditArticle::class)
{
    test()->actingAs($user);

    return livewire($page, ['record' => $article->getKey()]);
}

it('dispatches ChangesMerged with the kept fields and who saved them', function (): void {
    Event::fake([ChangesMerged::class, ConflictDetected::class]);

    $ion = openAs($this->ion, $this->article);
    openAs($this->ana, $this->article)->fillForm(['status' => 'review'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'X'])->call('save');

    Event::assertDispatched(ChangesMerged::class, fn (ChangesMerged $event): bool => $event->fields === ['status']
        && $event->theirName === 'Ana'
        && $event->user->is($this->ion)
        && $event->record->is($this->article));
    Event::assertNotDispatched(ConflictDetected::class);
});

it('dispatches ConflictDetected, then ConflictResolved with the choices', function (): void {
    Event::fake([ConflictDetected::class, ConflictResolved::class]);

    $ion = openAs($this->ion, $this->article);
    openAs($this->ana, $this->article)->fillForm(['title' => 'Ana', 'price' => 1])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion', 'price' => 2])->call('save');

    Event::assertDispatched(ConflictDetected::class, fn (ConflictDetected $event): bool => $event->fields === ['title', 'price']);
    Event::assertNotDispatched(ConflictResolved::class);

    $ion->setActionData(['choices' => ['title' => 'theirs', 'price' => 'mine']])->callMountedAction();

    Event::assertDispatched(ConflictResolved::class, fn (ConflictResolved $event): bool => $event->choices === ['title' => 'theirs', 'price' => 'mine']);

    expect($this->article->fresh())->title->toBe('Ana')->price->toBe('2.00');
});

it('dispatches nothing when nobody else saved', function (): void {
    Event::fake([ChangesMerged::class, ConflictDetected::class, ConflictResolved::class]);

    openAs($this->ion, $this->article)->fillForm(['title' => 'X'])->call('save');

    Event::assertNothingDispatched();
});

it('leaves an ignored field to plain Filament on an edit page', function (): void {
    $ion = openAs($this->ion, $this->article, EditArticleIgnoringViews::class);

    $this->article->update(['views' => 99, 'status' => 'review']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'X'])->call('save')->assertActionNotMounted('coeditResolve');

    // views is ignored: the stale form value is saved over theirs, as without Co-Edit; status is merged.
    expect($this->article->fresh())->views->toBe(0)->status->toBe('review');
});

it('does not ask about an ignored field both changed', function (): void {
    $ion = openAs($this->ion, $this->article, EditArticleIgnoringViews::class);

    $this->article->update(['views' => 99]);

    $this->actingAs($this->ion);
    $ion->fillForm(['views' => 5])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->views)->toBe(5);
});
