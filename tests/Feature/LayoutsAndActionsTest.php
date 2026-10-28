<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Tests\Fixtures\Article;
use Packstub\CoEdit\Tests\Fixtures\Category;
use Packstub\CoEdit\Tests\Fixtures\EditArticleWithNestedLayouts;
use Packstub\CoEdit\Tests\Fixtures\ListArticlesWithNestedLayouts;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->category = Category::query()->create(['name' => 'News']);
    $this->article = article(['meta' => ['color' => 'blue'], 'category_id' => $this->category->getKey()]);
});

function nestedPageAs($user, $article)
{
    test()->actingAs($user);

    return livewire(EditArticleWithNestedLayouts::class, ['record' => $article->getKey()]);
}

it('merges fields that take their path from a layout or a relationship group', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)
        ->fillForm(['meta' => ['color' => 'red'], 'category' => ['name' => 'Sport']])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])->call('save')->assertHasNoFormErrors()->assertNotified('Kept Ana\'s changes');

    expect($this->article->fresh())->title->toBe('Autumn')->meta->toBe(['color' => 'red'])
        ->and($this->category->fresh()->name)->toBe('Sport');
});

it('asks about a field in a layout both changed', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)->fillForm(['meta' => ['color' => 'red']])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['meta' => ['color' => 'green']])->call('save')->assertActionMounted('coeditResolve');

    expect($ion->instance()->coeditConflicts)->toBe(['meta']);
});

it('merges the same fields in a modal', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(ListArticlesWithNestedLayouts::class)->mountAction(TestAction::make('edit')->table($this->article));

    $this->actingAs($this->ana);
    livewire(ListArticlesWithNestedLayouts::class)
        ->mountAction(TestAction::make('edit')->table($this->article))
        ->fillForm(['meta' => ['color' => 'red'], 'category' => ['name' => 'Sport']])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])->callMountedAction()->assertHasNoFormErrors();

    expect($this->article->fresh())->title->toBe('Autumn')->meta->toBe(['color' => 'red'])
        ->and($this->category->fresh()->name)->toBe('Sport');
});

it('opens the dialog for a save started from a header action', function (string $action): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)->fillForm(['title' => 'Spring sale'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn']);
    $action === 'saveConfirmed'
        ? $ion->mountAction($action)->callMountedAction()
        : $ion->callAction($action);

    $ion->assertActionMounted('coeditResolve')
        ->callMountedAction(['choices' => ['title' => 'mine']]);

    expect($this->article->fresh()->title)->toBe('Autumn');
})->with(['saveFromHeader', 'saveConfirmed']);

it('never puts the room behind a presence component into the page', function (): void {
    $this->actingAs($this->ion);
    $room = PresenceStore::room($this->article);

    $html = nestedPageAs($this->ion, $this->article)->html();

    expect($html)->not->toContain(sha1($room))->not->toContain($room);
});

it('says the record is gone without guessing who deleted it', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    // Ana saved last, but deletes are not recorded: she is not named for one.
    nestedPageAs($this->ana, $this->article)->fillForm(['price' => 12])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])
        ->callAction('saveAfterSomeoneDeletes')
        ->assertNotified('Someone deleted this record while you were editing. Nothing was saved.');

    expect(Article::query()->count())->toBe(0);
});
