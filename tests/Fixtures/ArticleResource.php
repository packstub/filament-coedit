<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\CoEdit\Tables\PresenceColumn;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $slug = 'articles';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Select::make('status')->options(['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'])->required(),
            TextInput::make('price')->numeric(),
            Toggle::make('is_featured')->label('Featured'),
            Repeater::make('tags')->simple(TextInput::make('name')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
            PresenceColumn::make('presence'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'view' => ViewArticle::route('/{record}'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
