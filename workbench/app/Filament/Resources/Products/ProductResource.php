<?php

namespace Workbench\App\Filament\Resources\Products;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\CoEdit\Tables\PresenceColumn;
use Workbench\App\Filament\Resources\Products\Pages\EditProduct;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Models\Product;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->required(),
                    TextInput::make('sku')->label('SKU')->required(),
                    Select::make('status')
                        ->options(['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'])
                        ->required(),
                    TextInput::make('price')->numeric()->prefix('€')->required(),
                    TextInput::make('stock')->numeric()->integer(),
                    Toggle::make('is_featured')->label('Featured on the home page')->inline(false),
                    Textarea::make('description')->rows(4)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('sku')->label('SKU')->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'published' => 'success',
                    'review' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('price')->money('EUR'),
                TextColumn::make('stock')->numeric(),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
                PresenceColumn::make('presence'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
