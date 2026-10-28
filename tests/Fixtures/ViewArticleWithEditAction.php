<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Packstub\CoEdit\Concerns\HasPresence;

/** A view page whose header edits the same record in a modal. */
class ViewArticleWithEditAction extends ViewRecord
{
    use HasPresence;

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->coEdit()];
    }
}
