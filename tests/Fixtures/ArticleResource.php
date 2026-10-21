<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
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
            Select::make('category_id')->label('Category')->relationship('category', 'name'),
            Select::make('labels')->multiple()->relationship('labels', 'name'),
            TextInput::make('meta.color')->label('Colour'),
            FileUpload::make('cover')->disk('public')->directory('covers'),
            TextInput::make('internal_note')->hidden(fn (): bool => ! config('coedit-test.show_internal_note', false)),
            TextInput::make('views')->numeric(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title'),
                PresenceColumn::make('presence'),
            ])
            ->recordActions([
                EditAction::make()->coEdit(ignore: ['views']),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'view' => ViewArticle::route('/{record}'),
            'edit' => EditArticle::route('/{record}/edit'),
            'edit-ignoring' => EditArticleIgnoringViews::route('/{record}/edit-ignoring'),
        ];
    }
}
