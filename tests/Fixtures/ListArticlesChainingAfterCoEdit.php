<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/** before() and after() chained after coEdit(), which used to replace the merge. */
class ListArticlesChainingAfterCoEdit extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public static int $calls = 0;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()->coEdit()->before(fn () => static::$calls++)->after(fn () => static::$calls++),
        ]);
    }
}
