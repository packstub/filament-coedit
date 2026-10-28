<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

/** Translated with Filament's spatie-translatable plugin (lara-zeus/spatie-translatable). */
class PageResource extends Resource
{
    use Translatable;

    protected static ?string $model = Page::class;

    protected static ?string $slug = 'pages';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('body'),
            TextInput::make('slug'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('slug')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
