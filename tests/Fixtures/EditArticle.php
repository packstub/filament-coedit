<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

class EditArticle extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        // Filament dispatches RecordSaved for a header CreateAction too, with this page as the page.
        return [
            CreateAction::make('newCategory')
                ->model(Category::class)
                ->schema([TextInput::make('name')->required()]),
        ];
    }
}
