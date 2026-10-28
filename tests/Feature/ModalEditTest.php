<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Events\ChangesMerged;
use Packstub\CoEdit\Events\ConflictDetected;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Tests\Fixtures\ListArticles;
use Packstub\CoEdit\Tests\Fixtures\ListArticlesChainingAfterCoEdit;
use Packstub\CoEdit\Tests\Fixtures\ListArticlesFillingAfterCoEdit;
use Packstub\CoEdit\Tests\Fixtures\ListArticlesHaltingBefore;
use Packstub\CoEdit\Tests\Fixtures\ListArticlesWithOwnModalSave;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->article = article();
});

function openModalAs($user, $article)
{
    test()->actingAs($user);

    return livewire(ListArticles::class)->mountAction(TestAction::make('edit')->table($article));
}

function anaSavesInModal($article, array $changes): void
{
    openModalAs(test()->ana, $article)->fillForm($changes)->callMountedAction()->assertHasNoFormErrors();
}

it('saves a modal edit as usual when nobody else touched the record', function (): void {
    openModalAs($this->ion, $this->article)
        ->fillForm(['title' => 'Autumn launch'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotNotified('Kept Someone\'s changes');

    expect($this->article->fresh()->title)->toBe('Autumn launch')
        ->and(CoEdit::store()->lastSave(PresenceStore::room($this->article)))
        ->toMatchArray(['name' => 'Ion', 'fields' => ['Title']]);
});

it('keeps a colleague\'s change to a field you did not touch in the modal', function (): void {
    $ion = openModalAs($this->ion, $this->article);

    anaSavesInModal($this->article, ['status' => 'review', 'price' => 12]);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Kept Ana\'s changes');

    expect($this->article->fresh())
        ->title->toBe('Autumn launch')
        ->status->toBe('review')
        ->price->toBe('12.00');
});

it('stops once on a modal conflict, shows their value, and keeps yours on the second submit', function (): void {
    Event::fake([ConflictDetected::class]);

    $ion = openModalAs($this->ion, $this->article);

    anaSavesInModal($this->article, ['title' => 'Ana\'s title', 'status' => 'review']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion\'s title'])
        ->callMountedAction()
        ->assertNotified('Ana saved this record while you were editing')
        ->assertActionMounted(TestAction::make('edit')->table($this->article));

    expect($this->article->fresh()->title)->toBe('Ana\'s title');
    Event::assertDispatched(ConflictDetected::class, fn (ConflictDetected $event): bool => $event->fields === ['title'] && $event->theirName === 'Ana');

    $ion->callMountedAction()->assertHasNoFormErrors();

    expect($this->article->fresh())
        ->title->toBe('Ion\'s title')
        ->status->toBe('review');
});

it('stops again when a newer save lands on the same field before the second submit', function (): void {
    $ion = openModalAs($this->ion, $this->article);

    anaSavesInModal($this->article, ['title' => 'Ana 1']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Ion\'s title'])->callMountedAction()->assertNotified('Ana saved this record while you were editing');

    anaSavesInModal($this->article->fresh(), ['title' => 'Ana 2']);

    $this->actingAs($this->ion);
    $ion->callMountedAction();

    expect($this->article->fresh()->title)->toBe('Ana 2');

    $ion->callMountedAction();

    expect($this->article->fresh()->title)->toBe('Ion\'s title');
});

it('dispatches ChangesMerged for fields kept from a modal merge', function (): void {
    Event::fake([ChangesMerged::class]);

    $ion = openModalAs($this->ion, $this->article);
    anaSavesInModal($this->article, ['price' => 50]);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'X'])->callMountedAction();

    Event::assertDispatched(ChangesMerged::class, fn (ChangesMerged $event): bool => $event->fields === ['price'] && $event->record->is($this->article));
});

it('leaves ignored fields to plain Filament in the modal', function (): void {
    $ion = openModalAs($this->ion, $this->article);

    $this->article->update(['views' => 99]);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'X'])->callMountedAction()->assertNotNotified('Kept Someone\'s changes');

    // Ignored, so the modal's stale value is saved over theirs, exactly as without Co-Edit.
    expect($this->article->fresh()->views)->toBe(0);
});

it('shows who is on the record inside the modal', function (): void {
    openModalAs($this->ion, $this->article)->assertSeeLivewire(Presence::class);
});

it('waits for another save of the same record in the modal too', function (): void {
    Sleep::fake(syncWithCarbon: true);

    $release = CoEdit::store()->acquireSaveLock(PresenceStore::room($this->article));

    openModalAs($this->ion, $this->article)
        ->fillForm(['title' => 'Autumn launch'])
        ->callMountedAction()
        ->assertNotified(__('filament-coedit::coedit.busy'));

    expect($this->article->fresh()->title)->toBe('Spring launch');

    $release();

    openModalAs($this->ion, $this->article)->fillForm(['title' => 'Autumn launch'])->callMountedAction();

    expect($this->article->fresh()->title)->toBe('Autumn launch')
        ->and(CoEdit::store()->whileSaving(PresenceStore::room($this->article), fn () => 'free', wait: 0))->toBe('free');
});

it('releases the save lock by the end of the request when your own before() halts', function (): void {
    $room = PresenceStore::room($this->article);

    $this->actingAs($this->ion);

    livewire(ListArticlesHaltingBefore::class)
        ->mountAction(TestAction::make('edit')->table($this->article))
        ->fillForm(['title' => 'Autumn launch'])
        ->callMountedAction();

    expect($this->article->fresh()->title)->toBe('Spring launch');

    // What Laravel runs once the response is sent.
    app(DeferredCallbackCollection::class)->invoke();

    expect(CoEdit::store()->whileSaving($room, fn () => 'free', wait: 0))->toBe('free');
});

it('still merges when before() and after() are chained after coEdit()', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(ListArticlesChainingAfterCoEdit::class)->mountAction(TestAction::make('edit')->table($this->article));

    anaSavesInModal($this->article, ['status' => 'review']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])->callMountedAction()->assertNotified('Kept Ana\'s changes');

    expect($this->article->fresh())->title->toBe('Autumn launch')->status->toBe('review')
        ->and(ListArticlesChainingAfterCoEdit::$calls)->toBe(2)
        ->and(CoEdit::store()->lastSave(PresenceStore::room($this->article)))->toMatchArray(['name' => 'Ion', 'fields' => ['Title']]);
});

it('says so when afterFormFilled() chained after coEdit() leaves nothing to merge with', function (): void {
    config(['app.debug' => true]);
    $this->actingAs($this->ion);

    expect(fn () => livewire(ListArticlesFillingAfterCoEdit::class)
        ->mountAction(TestAction::make('edit')->table($this->article))
        ->fillForm(['title' => 'Autumn launch'])
        ->callMountedAction())
        ->toThrow(LogicException::class, 'Call afterFormFilled() before coEdit()');
});

it('reports it in production instead of failing the save', function (): void {
    config(['app.debug' => false]);
    Exceptions::fake();
    $this->actingAs($this->ion);

    livewire(ListArticlesFillingAfterCoEdit::class)
        ->mountAction(TestAction::make('edit')->table($this->article))
        ->fillForm(['title' => 'Autumn launch'])
        ->callMountedAction();

    Exceptions::assertReported(fn (LogicException $exception): bool => str_contains($exception->getMessage(), 'Call afterFormFilled() before coEdit()'));

    expect($this->article->fresh()->title)->toBe('Autumn launch');
});

it('hands an action\'s own save the modal\'s schema, and calls one given as a method name', function (string $name): void {
    $this->actingAs($this->ion);
    $ion = livewire(ListArticlesWithOwnModalSave::class)->mountAction(TestAction::make($name)->table($this->article));

    anaSavesInModal($this->article, ['status' => 'review']);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])->callMountedAction()->assertHasNoFormErrors();

    expect($this->article->fresh())->title->toBe('Autumn launch')->status->toBe('review');

    if ($name === 'edit') {
        expect(ListArticlesWithOwnModalSave::$schema)->toStartWith('mountedActions.');
    }
})->with(['closure' => 'edit', 'method' => 'editByMethod']);
