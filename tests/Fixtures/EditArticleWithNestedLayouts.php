<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

/** Nested layouts, and header actions that save the page, as "Save and publish" buttons do. */
class EditArticleWithNestedLayouts extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components(NestedLayouts::components());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveFromHeader')->action(fn () => $this->save()),
            Action::make('saveConfirmed')->requiresConfirmation()->action(fn () => $this->save()),
            // The race a deleted record is: gone between loading the page and merging.
            Action::make('saveAfterSomeoneDeletes')->action(function (): void {
                Article::query()->whereKey($this->getRecord()->getKey())->delete();

                $this->save();
            }),
        ];
    }
}
