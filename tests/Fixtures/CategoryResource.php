<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $slug = 'categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public static function getRelations(): array
    {
        return [ArticlesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListCategories::route('/'), 'edit' => EditCategory::route('/{record}/edit')];
    }
}
