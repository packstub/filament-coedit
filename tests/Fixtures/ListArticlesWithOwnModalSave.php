<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Edit actions with their own save, as a closure taking the modal's schema and as a page method. */
class ListArticlesWithOwnModalSave extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    public static ?string $schema = null;

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()
                ->action(function (array $data, Model $record, ?Schema $schema): void {
                    static::$schema = $schema?->getStatePath();
                    $record->update($data);
                })
                ->coEdit(),
            EditAction::make('editByMethod')->action('saveArticle')->coEdit(),
        ]);
    }

    public function saveArticle(EditAction $action, Model $record): void
    {
        $record->update($action->getData());
    }
}
