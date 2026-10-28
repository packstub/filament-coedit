<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\CoEdit;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

/** Overrides save() and writes without Filament's save, as the README shows. */
class EditArticleWithOwnSave extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ArticleResource::class;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        CoEdit::store()->whileSaving($this->getCoEditRoom(), function (): void {
            if (! $this->coeditMergeBeforeSave()) {
                return;
            }

            $this->getRecord()->update($this->form->getState());

            $this->coeditAfterSave();
        });
    }
}
