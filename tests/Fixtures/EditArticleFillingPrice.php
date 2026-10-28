<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

/** Sets a value in afterFill(), as pages do for derived or suggested values. */
class EditArticleFillingPrice extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    protected function afterFill(): void
    {
        $this->data['price'] = 99;
    }
}
