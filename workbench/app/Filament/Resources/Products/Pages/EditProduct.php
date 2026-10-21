<?php

namespace Workbench\App\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\EditRecord;
use Packstub\CoEdit\Concerns\InteractsWithCoEdit;
use Workbench\App\Filament\Resources\Products\ProductResource;

class EditProduct extends EditRecord
{
    use InteractsWithCoEdit;

    protected static string $resource = ProductResource::class;
}
