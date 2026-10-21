<?php

namespace Workbench\App\Filament\Resources\Suppliers;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\CoEdit\Tables\PresenceColumn;
use Workbench\App\Models\Supplier;

/**
 * A simple resource: no edit page, every edit happens in a modal.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('contact')->required(),
            TextInput::make('email')->email()->required(),
            TextInput::make('lead_time_days')->label('Lead time (days)')->numeric()->integer(),
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('name')->weight('medium'),
                TextColumn::make('contact'),
                TextColumn::make('email')->color('gray'),
                TextColumn::make('lead_time_days')->label('Lead time')->suffix(' days'),
                PresenceColumn::make('presence'),
            ])
            ->recordActions([EditAction::make()->coEdit()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSuppliers::route('/'),
        ];
    }
}
