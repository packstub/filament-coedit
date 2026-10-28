<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Tests\Fixtures\ArticlesRelationManager;
use Packstub\CoEdit\Tests\Fixtures\Category;
use Packstub\CoEdit\Tests\Fixtures\EditCategory;
use Packstub\CoEdit\Tests\Fixtures\ListArticlesMountingUsing;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->category = Category::query()->create(['name' => 'News']);
    $this->article = article(['category_id' => $this->category->getKey()]);
});

function rowModalAs($user, string $page, string $action, $record)
{
    test()->actingAs($user);

    return livewire($page)->mountAction(TestAction::make($action)->table($record));
}

it('merges in a modal whose mountUsing() asks for $form', function (): void {
    $ion = rowModalAs($this->ion, ListArticlesMountingUsing::class, 'edit', $this->article);

    rowModalAs($this->ana, ListArticlesMountingUsing::class, 'edit', $this->article)
        ->fillForm(['status' => 'review'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])->callMountedAction()->assertHasNoFormErrors()->assertNotified('Kept Ana\'s changes');

    expect($this->article->fresh())->title->toBe('Autumn')->status->toBe('review');
});

it('still merges with an action() set after coEdit(), only without announcing the save', function (): void {
    $ion = rowModalAs($this->ion, ListArticlesMountingUsing::class, 'editSavingAfter', $this->article);

    rowModalAs($this->ana, ListArticlesMountingUsing::class, 'edit', $this->article)->fillForm(['status' => 'review'])->callMountedAction();

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])->callMountedAction()->assertHasNoFormErrors();
    app(DeferredCallbackCollection::class)->invoke();

    expect($this->article->fresh())->title->toBe('Autumn')->status->toBe('review')
        ->and(CoEdit::store()->lastSave(PresenceStore::room($this->article))['name'])->toBe('Ana')
        ->and(CoEdit::store()->whileSaving(PresenceStore::room($this->article), fn () => 'free', wait: 0))->toBe('free');
});

it('merges a row edited in a relation manager on a Co-Edit page', function (): void {
    $manager = function ($user) {
        $this->actingAs($user);

        return livewire(ArticlesRelationManager::class, ['ownerRecord' => $this->category, 'pageClass' => EditCategory::class]);
    };

    $ion = $manager($this->ion)->mountAction(TestAction::make('edit')->table($this->article))->assertSeeLivewire(Presence::class);

    $manager($this->ana)->mountAction(TestAction::make('edit')->table($this->article))->fillForm(['status' => 'review'])->callMountedAction();

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn'])->callMountedAction()->assertHasNoFormErrors()->assertNotified('Kept Ana\'s changes');

    expect($this->article->fresh())->title->toBe('Autumn')->status->toBe('review');

    // The owner's page has its own room: the category, not the row.
    $this->actingAs($this->ion);
    livewire(EditCategory::class, ['record' => $this->category->getKey()])->assertSeeLivewire(Presence::class);
});
