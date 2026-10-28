<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Events\ConflictResolved;
use Packstub\CoEdit\Livewire\Presence;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Support\FormFields;
use Packstub\CoEdit\Tests\Fixtures\Article;
use Packstub\CoEdit\Tests\Fixtures\Category;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
use Packstub\CoEdit\Tests\Fixtures\EditArticleDefaultingTitle;
use Packstub\CoEdit\Tests\Fixtures\EditArticleFillingPrice;
use Packstub\CoEdit\Tests\Fixtures\EditArticleTrimmingTitle;
use Packstub\CoEdit\Tests\Fixtures\EditArticleWithLocale;
use Packstub\CoEdit\Tests\Fixtures\EditArticleWithOwnSave;
use Packstub\CoEdit\Tests\Fixtures\Label;
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
    $first = CoEdit::store()->lastSave($this->room)['version'];
    $ion->fillForm(['title' => 'Two'])->call('save');

    expect(CoEdit::store()->lastSave($this->room)['version'])->toBeGreaterThan($first)
        ->and($ion->get('coeditVersion'))->toBe(CoEdit::store()->lastSave($this->room)['version']);
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
    $presence = livewire(Presence::class, ['room' => $this->room])->call('acknowledge', PHP_INT_MAX);

    expect($presence->get('version'))->toBe(CoEdit::store()->lastSave($this->room)['version']);
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

it('keeps your unsaved edits when a header action saves another record', function (): void {
    $ion = editPageAs($this->ion, $this->article)
        ->fillForm(['title' => 'Autumn launch'])
        ->callAction('newCategory', ['name' => 'Gardening'])
        ->assertHasNoActionErrors();

    expect(CoEdit::store()->lastSave(PresenceStore::room($this->article)))->toBeNull();

    $ion->call('save')->assertHasNoFormErrors()->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->title)->toBe('Autumn launch');
});

it('ignores a save announcement asked for from the browser', function (): void {
    editPageAs($this->ion, $this->article)
        ->fillForm(['title' => 'Not saved yet'])
        ->call('coeditAfterSave')
        ->call('save');

    expect(CoEdit::store()->lastSave($this->room))->toMatchArray(['fields' => ['Title']])
        ->and($this->article->fresh()->title)->toBe('Not saved yet');
});

it('announces only your own fields after a save that kept theirs', function (): void {
    $ion = editPageAs($this->ion, $this->article);

    editPageAs($this->ana, $this->article)->fillForm(['status' => 'review'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])->call('save')->assertNotified('Kept Ana\'s changes');

    expect(CoEdit::store()->lastSave($this->room))->toMatchArray(['name' => 'Ion', 'fields' => ['Title']]);
});

it('waits for another save of the same record, and says so when it takes too long', function (): void {
    Sleep::fake(syncWithCarbon: true);

    $release = CoEdit::store()->acquireSaveLock($this->room);

    editPageAs($this->ion, $this->article)
        ->fillForm(['title' => 'Autumn launch'])
        ->call('save')
        ->assertNotified(__('filament-coedit::coedit.busy'));

    expect($this->article->fresh()->title)->toBe('Spring launch');

    $release();
    $release();

    editPageAs($this->ion, $this->article)->fillForm(['title' => 'Autumn launch'])->call('save');

    expect($this->article->fresh()->title)->toBe('Autumn launch');
});

it('does nothing when the conflict dialog is opened without a conflict', function (): void {
    Event::fake([ConflictResolved::class]);

    editPageAs($this->ion, $this->article)
        ->fillForm(['title' => 'Autumn launch'])
        ->mountAction('coeditResolve')
        ->callMountedAction();

    Event::assertNotDispatched(ConflictResolved::class);

    expect($this->article->fresh()->title)->toBe('Spring launch');
});

it('names related records only through the field\'s own relationship query', function (): void {
    $visible = Category::query()->create(['name' => 'News']);
    $secret = Category::query()->create(['name' => 'Board minutes']);

    $schema = Schema::make(livewire(ListArticles::class)->instance())
        ->model(Article::class)
        ->components([
            Select::make('category_id')->relationship('category', 'name', fn ($query) => $query->where('name', '!=', 'Board minutes')),
        ]);

    expect(FormFields::display($schema, 'category_id', $visible->getKey()))->toBe('News')
        ->and(FormFields::display($schema, 'category_id', $secret->getKey()))->toBe((string) $secret->getKey());
});

it('names related records of a field whose query mentions the pivot table', function (): void {
    $label = Label::query()->create(['name' => 'Urgent']);
    $this->article->labels()->attach($label);

    $schema = Schema::make(livewire(ListArticles::class)->instance())
        ->model($this->article)
        ->components([
            Select::make('labels')->multiple()->relationship('labels', 'name', fn ($query) => $query->orderBy('article_label.article_id')),
        ]);

    expect(FormFields::display($schema, 'labels', [$label->getKey()]))->toBe('Urgent');
});

it('tells apart two people of different user models with the same id', function (): void {
    $admin = new NamedUser(['name' => 'Admin']);
    $admin->forceFill(['id' => $this->ion->getKey()]);

    expect(CoEdit::who($admin)['user'])->not->toBe(CoEdit::who($this->ion)['user']);
});

it('names people under an enforced morph map that leaves the user model out', function (): void {
    Relation::enforceMorphMap(['article' => Article::class]);

    try {
        expect(CoEdit::who($this->ion)['user'])->toBe($this->ion::class.':'.$this->ion->getKey());
    } finally {
        Relation::requireMorphMap(false);
        Relation::morphMap([], merge: false);
    }
});

it('fills their version through the page\'s own fill hooks', function (): void {
    $this->actingAs($this->ion);

    livewire(EditArticleFillingPrice::class, ['record' => $this->article->getKey()])
        ->assertSet('data.price', 99)
        ->fillForm(['title' => 'Autumn launch'])
        ->call('save')
        ->assertNotNotified('Kept Someone\'s changes');

    expect($this->article->fresh())->title->toBe('Autumn launch')->price->toBe('99.00');
});

it('shows a name as text in the notifications, never as markup', function (): void {
    $mallory = user('<a href="https://example.com">Ana</a>');

    $ion = editPageAs($this->ion, $this->article);
    editPageAs($mallory, $this->article)->fillForm(['status' => 'review'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Autumn launch'])
        ->call('save')
        ->assertNotified(__('filament-coedit::coedit.taken.title', ['name' => e('<a href="https://example.com">Ana</a>')]));
});

it('never mistakes what your own save wrote for someone else\'s change', function (): void {
    $this->actingAs($this->ion);

    // The page saves the title trimmed: the database holds "Autumn", the form "  Autumn  ".
    $page = livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => '  Autumn  '])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->article->fresh()->title)->toBe('Autumn');

    $page->fillForm(['title' => 'Winter', 'price' => 20])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotNotified('Kept Someone\'s changes');

    expect($page->instance()->mountedActions)->toBe([])
        ->and($this->article->fresh())->title->toBe('Winter')->price->toBe('20.00')
        ->and(CoEdit::store()->lastSave($this->room)['fields'])->toBe(['Title', 'Price']);
});

it('keeps a colleague\'s change to a field your last save stored differently from how you typed it', function (): void {
    $this->actingAs($this->ion);

    $ion = livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => '  Autumn  '])
        ->call('save')
        ->assertHasNoFormErrors();

    // Ana renames it; Ion's form still says "  Autumn  ", which he has not touched since his save.
    $this->actingAs($this->ana);
    livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])->fillForm(['title' => 'Spring'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['price' => 20])->call('save')->assertHasNoFormErrors();

    expect($ion->instance()->mountedActions)->toBe([])
        ->and($this->article->fresh())->title->toBe('Spring')->price->toBe('20.00');
});

it('still asks when you change that field again after saving it', function (): void {
    $this->actingAs($this->ion);

    $ion = livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => '  Autumn  '])
        ->call('save');

    $this->actingAs($this->ana);
    livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])->fillForm(['title' => 'Spring'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Winter'])->call('save')->assertActionMounted('coeditResolve');

    expect($this->article->fresh()->title)->toBe('Spring');
});

it('gets their change to a field your last save stored differently', function (): void {
    $this->actingAs($this->ion);

    $ion = livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => '  Autumn  '])
        ->call('save');

    $this->actingAs($this->ana);
    livewire(EditArticleTrimmingTitle::class, ['record' => $this->article->getKey()])->fillForm(['title' => 'Spring'])->call('save');

    $this->actingAs($this->ion);
    $ion->call('coeditPull')->assertSet('data.title', 'Spring');
});

it('merges on a page with its own save() as the README shows it', function (): void {
    $this->actingAs($this->ion);

    $ion = livewire(EditArticleWithOwnSave::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => 'Autumn'])
        ->call('save');

    expect(CoEdit::store()->lastSave($this->room))->toMatchArray(['name' => 'Ion', 'fields' => ['Title']]);

    anaSavesStatus($this->article);

    // His own earlier save is no conflict; Ana's status is kept.
    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Winter'])->call('save');

    expect($ion->instance()->mountedActions)->toBe([])
        ->and($this->article->fresh())->title->toBe('Winter')->status->toBe('review');
});

function anaSavesStatus($article): void
{
    test()->actingAs(test()->ana);
    livewire(EditArticle::class, ['record' => $article->getKey()])->fillForm(['status' => 'review'])->call('save');
}

it('leaves the page as it was after filling their version, not only the form', function (): void {
    $this->actingAs($this->ion);

    $ion = livewire(EditArticleWithLocale::class, ['record' => $this->article->getKey()])->set('activeLocale', 'ro');

    anaSavesStatus($this->article);

    // The merge fills Ana's version through fillForm(), which resets the locale; the save must still be in Ion's.
    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Toamnă'])->call('save')->assertHasNoFormErrors()->assertSet('activeLocale', 'ro');

    expect($this->article->fresh())->title->toBe('Toamnă')->internal_note->toBe('ro')->status->toBe('review');
});

it('shows your own validation errors before asking about a conflict', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(EditArticle::class, ['record' => $this->article->getKey()]);

    anaSavesStatus($this->article);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => '', 'status' => 'published'])
        ->call('save')
        ->assertHasFormErrors(['title' => 'required']);

    expect($ion->instance()->mountedActions)->toBe([])
        ->and($ion->instance()->coeditConflicts)->toBe([])
        ->and($this->article->fresh()->status)->toBe('review');

    $ion->fillForm(['title' => 'Autumn'])->call('save')->assertActionMounted('coeditResolve');
});

it('lets beforeValidate() fill a blank field, with or without a conflict', function (): void {
    $this->actingAs($this->ion);
    livewire(EditArticleDefaultingTitle::class, ['record' => $this->article->getKey()])
        ->fillForm(['title' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->article->fresh()->title)->toBe('Untitled');

    $ion = livewire(EditArticleDefaultingTitle::class, ['record' => $this->article->getKey()]);
    anaSavesStatus($this->article);

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => '', 'status' => 'published'])->call('save')->assertHasNoFormErrors()->assertActionMounted('coeditResolve');
});

it('ignores the mount hook called again from the browser', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(EditArticle::class, ['record' => $this->article->getKey()])->fillForm(['title' => 'Autumn']);
    $base = $ion->instance()->coeditBase;

    try {
        $ion->call('mountInteractsWithCoEdit');
    } catch (Throwable) {
        // Livewire 4 refuses the call outright; Livewire 3 lets it through.
    }

    expect($ion->instance()->coeditBase)->toBe($base)->and($base['title'])->toBe('Spring launch');
});
