<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A relation manager on an edit page that has Co-Edit itself, editing its rows in modals. */
class ArticlesRelationManager extends RelationManager
{
    protected static string $relationship = 'articles';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Select::make('status')->options(['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'])->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->recordActions([EditAction::make()->coEdit()]);
    }
}
