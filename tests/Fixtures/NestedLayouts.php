<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;

/** Fields whose path comes from their layout: a section with its own state path, a relationship group. */
class NestedLayouts
{
    /** @return array<Component> */
    public static function components(): array
    {
        return [
            TextInput::make('title')->required(),
            TextInput::make('price')->numeric(),
            Section::make('Appearance')->statePath('meta')->schema([TextInput::make('color')->label('Colour'), TextInput::make('size')]),
            Group::make()->relationship('category')->schema([TextInput::make('name')->label('Category name')]),
        ];
    }
}
