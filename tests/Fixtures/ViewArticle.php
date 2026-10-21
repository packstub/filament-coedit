<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\ViewRecord;
use Packstub\CoEdit\Concerns\HasPresence;

class ViewArticle extends ViewRecord
{
    use HasPresence;

    protected static string $resource = ArticleResource::class;
}
