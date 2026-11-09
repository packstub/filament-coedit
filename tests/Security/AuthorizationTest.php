<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Events\ChangesMerged;
use Packstub\CoEdit\Events\ConflictDetected;
use Packstub\CoEdit\Support\FormFields;
use Packstub\CoEdit\Tests\Fixtures\Article;
use Packstub\CoEdit\Tests\Fixtures\Category;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
use Packstub\CoEdit\Tests\Fixtures\ForbiddingArticlePolicy;
use Packstub\CoEdit\Tests\Fixtures\ListArticles;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->article = article();
});

it('refuses "Get their changes" to a person who may no longer edit the record', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(EditArticle::class, ['record' => $this->article->getKey()]);

    $this->article->update(['title' => 'Changed by Ana']);
    $ion->call('coeditPull')->assertOk();
    expect($ion->get('data.title'))->toBe('Changed by Ana');

    $this->article->update(['title' => 'Changed again']);
    Gate::policy(Article::class, ForbiddingArticlePolicy::class);

    $ion->call('coeditPull')->assertStatus(403);
});

/** Signs in on the panel's own guard only, as a request through a panel with `authGuard('staff')` arrives: the default guard stays empty. */
function signInOnStaffGuard($user): void
{
    auth()->guard('staff')->setUser($user);
}

it('names the panel guard\'s user in the events when the default guard is empty', function (): void {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    Filament::getPanel('admin')->authGuard('staff');
    Event::fake([ChangesMerged::class, ConflictDetected::class]);

    signInOnStaffGuard($this->ion);
    $ion = livewire(EditArticle::class, ['record' => $this->article->getKey()]);

    signInOnStaffGuard($this->ana);
    livewire(EditArticle::class, ['record' => $this->article->getKey()])->fillForm(['status' => 'review'])->call('save');

    signInOnStaffGuard($this->ion);
    expect(auth()->user())->toBeNull()->and(CoEdit::user()?->is($this->ion))->toBeTrue();

    $ion->fillForm(['title' => 'Ion'])->call('save');

    Event::assertDispatched(ChangesMerged::class, fn (ChangesMerged $event): bool => $event->user?->is($this->ion) === true && $event->fields === ['status'] && $event->theirName === 'Ana');

    // A second round, both changing the price: the conflict names Ion too.
    signInOnStaffGuard($this->ana);
    livewire(EditArticle::class, ['record' => $this->article->getKey()])->fillForm(['price' => 5])->call('save');

    signInOnStaffGuard($this->ion);
    $ion->fillForm(['price' => 7])->call('save');

    Event::assertDispatched(ConflictDetected::class, fn (ConflictDetected $event): bool => $event->user?->is($this->ion) === true && $event->fields === ['price']);
});

it('names the panel guard\'s user in a modal edit\'s events too', function (): void {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    Filament::getPanel('admin')->authGuard('staff');
    Event::fake([ChangesMerged::class]);

    signInOnStaffGuard($this->ion);
    $list = livewire(ListArticles::class)->mountAction(TestAction::make('edit')->table($this->article));

    $this->article->update(['status' => 'review']);

    $list->setActionData(['title' => 'Ion'])->callMountedAction()->assertHasNoFormErrors();

    Event::assertDispatched(ChangesMerged::class, fn (ChangesMerged $event): bool => $event->user?->is($this->ion) === true && $event->fields === ['status']);
});

it('only names related records the select itself would offer in the dialog', function (): void {
    $shown = Category::query()->create(['name' => 'Shown']);
    $hidden = Category::query()->create(['name' => 'Hidden']);

    $this->actingAs($this->ion);
    $page = livewire(EditArticle::class, ['record' => $this->article->getKey()])->instance();

    $schema = Schema::make($page)
        ->model($this->article)
        ->statePath('data')
        ->components([
            Select::make('category_id')->relationship('category', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('name', '!=', 'Hidden')),
        ]);

    // A key the browser sends for a record outside the field's query shows as the key, never as its name.
    expect(FormFields::display($schema, 'category_id', $shown->getKey()))->toBe('Shown')
        ->and(FormFields::display($schema, 'category_id', $hidden->getKey()))->toBe((string) $hidden->getKey());
});
