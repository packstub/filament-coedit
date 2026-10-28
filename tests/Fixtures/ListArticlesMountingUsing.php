<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** mountUsing() with a `Schema $form` argument, as Filament's docs write it, and an action() set after coEdit(). */
class ListArticlesMountingUsing extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()
                ->mountUsing(fn (Schema $form, Model $record) => $form->fill($record->attributesToArray()))
                ->coEdit(),
            EditAction::make('editSavingAfter')
                ->coEdit()
                ->action(fn (array $data, Model $record) => $record->update($data)),
        ]);
    }
}
