<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** A translated rich text field: the form holds it in another shape than the locales waiting aside. */
class EditPageWithRichBody extends EditPage
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            RichEditor::make('body'),
            TextInput::make('slug'),
        ]);
    }
}
