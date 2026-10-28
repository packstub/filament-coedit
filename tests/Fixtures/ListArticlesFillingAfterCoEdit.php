<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/** afterFormFilled() chained after coEdit(): the fingerprint is gone, which must not pass silently. */
class ListArticlesFillingAfterCoEdit extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()->coEdit()->afterFormFilled(fn () => null),
        ]);
    }
}
