<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

/** Keeps page state that fillForm() resets, as Filament's Translatable concern does with the active locale. */
class EditArticleWithLocale extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    public string $activeLocale = 'en';

    protected function fillForm(): void
    {
        $this->activeLocale = 'en';

        parent::fillForm();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['internal_note'] = $this->activeLocale;

        return $data;
    }
}
