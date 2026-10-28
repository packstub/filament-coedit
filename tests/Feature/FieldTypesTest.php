<?php

use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Packstub\CoEdit\Support\FormFields;
use Packstub\CoEdit\Tests\Fixtures\Category;
use Packstub\CoEdit\Tests\Fixtures\EditArticle;
use Packstub\CoEdit\Tests\Fixtures\Label;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Storage::fake('public');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->news = Category::query()->create(['name' => 'News']);
    $this->guides = Category::query()->create(['name' => 'Guides']);
    $this->urgent = Label::query()->create(['name' => 'Urgent']);
    $this->evergreen = Label::query()->create(['name' => 'Evergreen']);
    $this->article = article(['category_id' => $this->news->getKey(), 'meta' => ['color' => 'red']]);
});

function ionOpens($article)
{
    test()->actingAs(test()->ion);

    return livewire(EditArticle::class, ['record' => $article->getKey()]);
}

function anaEdits($article, array $changes): void
{
    test()->actingAs(test()->ana);
    livewire(EditArticle::class, ['record' => $article->getKey()])->fillForm($changes)->call('save')->assertHasNoFormErrors();
    test()->actingAs(test()->ion);
}

it('keeps their BelongsToMany selection when you edited something else', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['labels' => [$this->urgent->getKey(), $this->evergreen->getKey()]]);

    $ion->fillForm(['title' => 'Autumn launch'])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->labels->pluck('name')->sort()->values()->all())->toBe(['Evergreen', 'Urgent'])
        ->and($this->article->fresh()->title)->toBe('Autumn launch');
});

it('asks about a BelongsToMany both changed, naming the options', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['labels' => [$this->urgent->getKey()]]);

    $ion->fillForm(['labels' => [$this->evergreen->getKey()]])
        ->call('save')
        ->assertActionMounted('coeditResolve')
        ->assertMountedActionModalSee(['Labels', 'Evergreen', 'Urgent']);
});

it('keeps their BelongsTo choice and names it in the dialog when both changed it', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['category_id' => $this->guides->getKey()]);

    $ion->fillForm(['category_id' => null])
        ->call('save')
        ->assertActionMounted('coeditResolve')
        ->assertMountedActionModalSee(['Category', 'Guides', '(empty)']);
});

it('merges a nested field as part of its top-level attribute', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['meta.color' => 'blue']);

    $ion->fillForm(['title' => 'X'])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->meta)->toBe(['color' => 'blue']);
});

it('labels a conflict on a nested attribute by its field', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['meta.color' => 'blue']);

    $ion->fillForm(['meta.color' => 'green'])
        ->call('save')
        ->assertActionMounted('coeditResolve')
        ->assertMountedActionModalSee(['Colour', 'green', 'blue']);
});

it('does not take a file upload refilled with new item keys for a change', function (): void {
    $this->article->update(['cover' => 'covers/a.jpg']);
    Storage::disk('public')->put('covers/a.jpg', 'x');

    $ion = ionOpens($this->article);

    anaEdits($this->article, ['title' => 'Ana\'s title']);

    $ion->fillForm(['price' => 15])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh())
        ->cover->toBe('covers/a.jpg')
        ->title->toBe('Ana\'s title');
});

it('keeps a cover someone else uploaded', function (): void {
    $ion = ionOpens($this->article);

    $this->actingAs($this->ana);
    livewire(EditArticle::class, ['record' => $this->article->getKey()])
        ->set('data.cover', [UploadedFile::fake()->image('new.jpg')])
        ->call('save')
        ->assertHasNoFormErrors();

    $cover = $this->article->fresh()->cover;
    expect($cover)->toStartWith('covers/');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'X'])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->cover)->toBe($cover);
});

it('never overwrites a field hidden from you that someone else changed', function (): void {
    $ion = ionOpens($this->article);

    $this->article->update(['internal_note' => 'Legal approved']);

    $ion->fillForm(['title' => 'X'])->call('save')->assertActionNotMounted('coeditResolve');

    expect($this->article->fresh()->internal_note)->toBe('Legal approved');
});

it('never asks about a toggle: it can only be theirs or the same as theirs', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['is_featured' => true, 'status' => 'review', 'title' => 'Ana']);

    $ion->fillForm(['status' => 'published', 'title' => 'Ion'])
        ->call('save')
        ->assertActionMounted('coeditResolve')
        ->assertMountedActionModalSee(['In review', 'Published'])
        ->assertMountedActionModalDontSee('Featured');

    expect($this->article->fresh()->is_featured)->toBeTrue();
});

it('cuts very long values in the dialog', function (): void {
    $ion = ionOpens($this->article);

    anaEdits($this->article, ['title' => str_repeat('a', 300)]);

    $ion->fillForm(['title' => 'Mine'])
        ->call('save')
        ->assertMountedActionModalSee(str_repeat('a', 140).'...')
        ->assertMountedActionModalDontSee(str_repeat('a', 141));
});

it('shows a rich document as its text in the dialog', function (): void {
    $form = ionOpens($this->article)->instance()->form;
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hel'], ['type' => 'text', 'text' => 'lo', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' world']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bye']]],
    ]];

    expect(FormFields::display($form, 'title', $doc))->toBe('Hello world Bye');
});
