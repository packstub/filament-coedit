<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/** beforeFormValidated() chained after coEdit() replaces the merge, which must not pass silently. */
class ListArticlesValidatingAfterCoEdit extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()->coEdit()->beforeFormValidated(fn () => null),
            EditAction::make('editQuietly')->coEdit(presence: false),
        ]);
    }
}
