<?php

namespace Packstub\CoEdit\Tests\Fixtures;

class EditPageIgnoringBody extends EditPage
{
    protected function getCoEditIgnoredFields(): array
    {
        return ['body'];
    }
}
