<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }
}
