<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/** A table whose edit action has its own before() that stops the save, as Filament's docs suggest. */
class ListArticlesHaltingBefore extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()->before(fn (EditAction $action) => $action->halt())->coEdit(),
        ]);
    }
}
