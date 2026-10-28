<?php

namespace Packstub\CoEdit\Tests\Fixtures;

/** A beforeSave() that stops the next save, as a business rule checked there would. */
class EditArticleHaltingOnce extends EditArticle
{
    public static bool $halt = false;

    protected function beforeSave(): void
    {
        if (static::$halt) {
            static::$halt = false;

            $this->halt();
        }
    }
}
