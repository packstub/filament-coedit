<?php

namespace Workbench\App\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Products\ProductResource;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
}
