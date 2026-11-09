<?php

use Packstub\CoEdit\Tests\Fixtures\Article;
use Packstub\CoEdit\Tests\Fixtures\User;
use Packstub\CoEdit\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Security', 'Unit');

function user(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret']);
}

/** @param  array<string, mixed>  $attributes */
function article(array $attributes = []): Article
{
    return Article::query()->create([
        'title' => 'Spring launch',
        'status' => 'draft',
        'price' => 10,
        'is_featured' => false,
        'tags' => ['news'],
        ...$attributes,
    ]);
}
