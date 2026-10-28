<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

/** Saves a different value than the form holds, as pages that tidy input do. */
class EditArticleTrimmingTitle extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['title'] = trim($data['title']);

        return $data;
    }
}
