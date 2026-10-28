<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** A form that leaves a translatable attribute out; the plugin still saves it for the other locales. */
class EditPageWithoutBody extends EditPage
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug'),
        ]);
    }
}
