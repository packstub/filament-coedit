<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Support\FormFields;
use Packstub\CoEdit\Tests\Fixtures\Article;
use Packstub\CoEdit\Tests\Fixtures\Category;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
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

    expect($this->article->fresh())->title->toBe('Autumn')->meta->toBe(['color' => 'red', 'size' => null])
        ->and($this->category->fresh()->name)->toBe('Sport');
});

it('asks about a field in a layout both changed', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)->fillForm(['meta' => ['color' => 'red']])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['meta' => ['color' => 'green']])->call('save')->assertActionMounted('coeditResolve');

    expect($ion->instance()->coeditConflicts)->toBe(['meta.color']);

    $ion->setActionData(['choices' => ['meta__color' => 'theirs']])->callMountedAction()->assertHasNoFormErrors();

    expect($this->article->fresh()->meta)->toBe(['color' => 'red', 'size' => null]);
});

it('merges two fields of one layout field by field', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)->fillForm(['meta' => ['color' => 'red']])->call('save')->assertHasNoFormErrors();

    $this->actingAs($this->ion);
    $ion->fillForm(['meta' => ['size' => 'L']])->call('save')->assertHasNoFormErrors()->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->meta)->toBe(['color' => 'red', 'size' => 'L']);
});

it('compares a relationship group by its fields, not the related record\'s id', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    // Someone points the article at another category with the same name.
    $this->article->update(['category_id' => Category::query()->create(['name' => 'News'])->getKey()]);

    $this->actingAs($this->ion);
    $ion->fillForm(['category' => ['name' => 'Sport']])->call('save')->assertHasNoFormErrors()->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->category->name)->toBe('Sport');
});

it('merges two fields of one layout field by field in a modal', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(ListArticlesWithNestedLayouts::class)->mountAction(TestAction::make('edit')->table($this->article));

    $this->actingAs($this->ana);
    livewire(ListArticlesWithNestedLayouts::class)
        ->mountAction(TestAction::make('edit')->table($this->article))
        ->fillForm(['meta' => ['color' => 'red']])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $this->actingAs($this->ion);
    $ion->fillForm(['meta' => ['size' => 'L']])->callMountedAction()->assertHasNoFormErrors()->assertNotNotified('Ana saved this record while you were editing');

    expect($this->article->fresh()->meta)->toBe(['color' => 'red', 'size' => 'L']);
});

it('asks about one field of a layout both changed in a modal', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(ListArticlesWithNestedLayouts::class)->mountAction(TestAction::make('edit')->table($this->article));

    $this->actingAs($this->ana);
    livewire(ListArticlesWithNestedLayouts::class)
        ->mountAction(TestAction::make('edit')->table($this->article))
        ->fillForm(['meta' => ['color' => 'red']])
        ->callMountedAction();

    $this->actingAs($this->ion);
    $ion->fillForm(['meta' => ['color' => 'green', 'size' => 'L']])->callMountedAction()->assertNotified();

    expect($this->article->fresh()->meta)->toBe(['color' => 'red', 'size' => null]);

    // Submitting again keeps yours.
    $ion->callMountedAction()->assertHasNoFormErrors();

    expect($this->article->fresh()->meta)->toBe(['color' => 'green', 'size' => 'L']);
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

    expect($this->article->fresh())->title->toBe('Autumn')->meta->toBe(['color' => 'red', 'size' => null])
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

it('runs nothing after the save in a header action that stops at a conflict', function (): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)->fillForm(['title' => 'Spring sale'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])->callAction('saveAndPublish')->assertActionMounted('coeditResolve');

    expect($this->article->fresh())->title->toBe('Spring sale')->status->toBe('draft');
});

it('keeps the dialog open when Save is pressed again while a modal is open', function (string $open): void {
    $ion = nestedPageAs($this->ion, $this->article);

    nestedPageAs($this->ana, $this->article)->fillForm(['title' => 'Spring sale'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn']);

    // Cmd+S reaches save() while any modal is open: the dialog itself, or another action's.
    $open === 'the dialog' ? $ion->call('save') : $ion->mountAction('saveConfirmed');

    $ion->call('save')->assertActionMounted('coeditResolve');

    expect($this->article->fresh()->title)->toBe('Spring sale');
})->with(['the dialog', 'a header action\'s modal']);

it('merges each field by its path, a repeater as a whole, and ignores everything under an ignored path', function (): void {
    $this->article->update(['tags' => ['a', 'b']]);
    $this->actingAs($this->ion);

    $form = livewire(EditArticle::class, ['record' => $this->article->getKey()])->instance()->form;
    $nested = nestedPageAs($this->ion, $this->article)->instance()->form;

    expect(FormFields::names($form))->toBe(['title', 'status', 'price', 'is_featured', 'tags', 'category_id', 'labels', 'meta.color', 'cover', 'internal_note', 'views'])
        ->and(FormFields::names($nested))->toBe(['title', 'price', 'meta.color', 'meta.size', 'category.name'])
        ->and(FormFields::names($nested, ['meta', 'categ']))->toBe(['title', 'price', 'category.name']);
});
