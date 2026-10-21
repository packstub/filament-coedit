<?php

namespace Packstub\CoEdit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'is_featured' => 'boolean',
        'tags' => 'array',
    ];
}
