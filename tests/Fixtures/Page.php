<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Page extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['title', 'body'];
}
