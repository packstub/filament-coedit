<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

class EditArticle extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;
}
