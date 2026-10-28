<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListArticlesWithNestedLayouts extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()->schema(NestedLayouts::components())->coEdit(),
        ]);
    }
}
