<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

/** Fills a blank required field in beforeValidate(), as pages with defaults do. */
class EditArticleDefaultingTitle extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    protected function beforeValidate(): void
    {
        if (blank($this->data['title'] ?? null)) {
            $this->data['title'] = 'Untitled';
        }
    }
}
