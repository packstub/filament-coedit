<?php

use Illuminate\Support\Arr;

it('translates every string in every language', function (string $locale): void {
    $english = Arr::dot(require __DIR__.'/../../resources/lang/en/coedit.php');
    $other = Arr::dot(require __DIR__."/../../resources/lang/{$locale}/coedit.php");

    expect(array_keys($other))->toEqualCanonicalizing(array_keys($english));

    foreach ($english as $key => $text) {
        preg_match_all('/:\w+/', $text, $placeholders);
        preg_match_all('/:\w+/', $other[$key], $translated);

        expect(array_unique($translated[0]))->toEqualCanonicalizing(array_unique($placeholders[0]), "{$locale}.{$key} keeps its placeholders");
        expect(substr_count($other[$key], '|'))->toBe(substr_count($text, '|'), "{$locale}.{$key} keeps its plural forms");
    }
})->with(['ro', 'ru', 'de']);
