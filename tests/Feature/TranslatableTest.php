<?php

use Filament\Facades\Filament;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Presence\PresenceStore;
use Packstub\CoEdit\Support\FormFields;
use Packstub\CoEdit\Tests\Fixtures\EditPage;
use Packstub\CoEdit\Tests\Fixtures\EditPageIgnoringBody;
use Packstub\CoEdit\Tests\Fixtures\Page;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->ion = user('Ion');
    $this->ana = user('Ana');
    $this->page = Page::query()->create([
        'title' => ['en' => 'Autumn', 'ro' => 'Toamnă'],
        'body' => ['en' => 'Leaves fall', 'ro' => 'Cad frunzele'],
        'slug' => 'autumn',
    ]);
});

function translatedPageAs($user, $page, string $locale = 'en')
{
    test()->actingAs($user);

    $component = livewire(EditPage::class, ['record' => $page->getKey()]);

    return $locale === 'en' ? $component : $component->set('activeLocale', $locale);
}

it('keeps a colleague\'s edit to another locale than the one you save in', function (): void {
    $ion = translatedPageAs($this->ion, $this->page);

    translatedPageAs($this->ana, $this->page, 'ro')->fillForm(['title' => 'Toamna de aur'])->call('save')->assertHasNoFormErrors();

    $this->actingAs($this->ion);
    $ion->fillForm(['slug' => 'autumn-2026'])->call('save')->assertHasNoFormErrors()->assertActionNotMounted('coeditResolve');

    $page = $this->page->fresh();
    expect($page->getTranslation('title', 'ro'))->toBe('Toamna de aur')
        ->and($page->getTranslation('title', 'en'))->toBe('Autumn')
        ->and($page->slug)->toBe('autumn-2026');
});

it('asks about a locale both changed, naming the locale', function (): void {
    $ion = translatedPageAs($this->ion, $this->page, 'ro');

    translatedPageAs($this->ana, $this->page, 'ro')->fillForm(['title' => 'Toamna de aur'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Toamna târzie'])->call('save')
        ->assertActionMounted('coeditResolve')
        ->assertMountedActionModalSee(['Title (RO)', 'Toamna târzie', 'Toamna de aur']);

    expect($ion->instance()->coeditConflicts)->toBe(['title@ro']);

    $ion->setActionData(['choices' => [FormFields::key('title@ro') => 'mine']])->callMountedAction()->assertHasNoFormErrors();

    expect($this->page->fresh()->getTranslation('title', 'ro'))->toBe('Toamna târzie')
        ->and(CoEdit::store()->lastSave(PresenceStore::room($this->page))['fields'])->toBe(['Title (RO)']);
});

it('merges two locales of one field edited by two people', function (): void {
    $ion = translatedPageAs($this->ion, $this->page, 'ro');

    translatedPageAs($this->ana, $this->page)->fillForm(['title' => 'Golden autumn'])->call('save');

    $this->actingAs($this->ion);
    $ion->fillForm(['title' => 'Toamna de aur'])->call('save')->assertHasNoFormErrors()->assertActionNotMounted('coeditResolve');

    $page = $this->page->fresh();
    expect($page->getTranslation('title', 'en'))->toBe('Golden autumn')
        ->and($page->getTranslation('title', 'ro'))->toBe('Toamna de aur');
});

it('keeps the merge in step when you switch locale after loading', function (): void {
    $ion = translatedPageAs($this->ion, $this->page);
    $ion->fillForm(['body' => 'Leaves fall slowly']);

    // Ion moves on to Romanian: his English edit waits in otherLocaleData.
    $ion->set('activeLocale', 'ro')->fillForm(['body' => 'Cad frunzele încet']);

    translatedPageAs($this->ana, $this->page)->fillForm(['title' => 'Golden autumn'])->call('save');

    $this->actingAs($this->ion);
    $ion->call('save')->assertHasNoFormErrors()->assertActionNotMounted('coeditResolve')->assertNotified('Kept Ana\'s changes');

    $page = $this->page->fresh();
    expect($page->getTranslation('title', 'en'))->toBe('Golden autumn')
        ->and($page->getTranslation('title', 'ro'))->toBe('Toamnă')
        ->and($page->getTranslation('body', 'en'))->toBe('Leaves fall slowly')
        ->and($page->getTranslation('body', 'ro'))->toBe('Cad frunzele încet');
});

it('pulls a colleague\'s edit to a locale the form does not show', function (): void {
    $ion = translatedPageAs($this->ion, $this->page);

    translatedPageAs($this->ana, $this->page, 'ro')->fillForm(['title' => 'Toamna de aur'])->call('save');

    $this->actingAs($this->ion);
    $ion->call('coeditPull')->assertNotified();

    expect($ion->instance()->otherLocaleData['ro']['title'])->toBe('Toamna de aur')
        ->and($ion->instance()->data['title'])->toBe('Autumn');

    $ion->set('activeLocale', 'ro')->assertSet('data.title', 'Toamna de aur');
});

it('treats each locale of a translated field as a field of its own', function (): void {
    expect((fn (): array => $this->coeditFields())->call(translatedPageAs($this->ion, $this->page, 'ro')->instance()))
        ->toHaveKeys(['title@en', 'title@ro', 'body@en', 'body@ro', 'slug'])
        ->not->toHaveKey('title');
});

it('leaves an ignored translated field to plain Filament in every locale', function (): void {
    $this->actingAs($this->ion);
    $ion = livewire(EditPageIgnoringBody::class, ['record' => $this->page->getKey()]);

    expect((fn (): array => $this->coeditFields())->call($ion->instance()))->toHaveKeys(['title@en', 'title@ro', 'slug'])
        ->not->toHaveKeys(['body@en', 'body@ro', 'body']);
});
