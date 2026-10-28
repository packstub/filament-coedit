<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;

class EditPage extends EditRecord
{
    use InteractsWithCoEdit;
    use Translatable;

    protected static string $resource = PageResource::class;
}
