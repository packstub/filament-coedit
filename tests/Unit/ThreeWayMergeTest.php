<?php

use Illuminate\Support\Carbon;
use Packstub\CoEdit\Merge\ThreeWayMerge;

enum Stage: string
{
    case Draft = 'draft';
    case Live = 'live';
}

it('takes their value for a field only they changed', function (): void {
    $result = ThreeWayMerge::merge(
        base: ['title' => 'A', 'status' => 'draft'],
        mine: ['title' => 'A', 'status' => 'draft'],
        theirs: ['title' => 'A', 'status' => 'review'],
    );

    expect($result->data)->toBe(['title' => 'A', 'status' => 'review'])
        ->and($result->taken)->toBe(['status'])
        ->and($result->hasConflicts())->toBeFalse();
});

it('keeps my value for a field only I changed', function (): void {
    $result = ThreeWayMerge::merge(
        base: ['title' => 'A', 'status' => 'draft'],
        mine: ['title' => 'B', 'status' => 'draft'],
        theirs: ['title' => 'A', 'status' => 'review'],
    );

    expect($result->data)->toBe(['title' => 'B', 'status' => 'review'])
        ->and($result->taken)->toBe(['status'])
        ->and($result->conflicts)->toBe([]);
});

it('does not call the same change on both sides a conflict', function (): void {
    $result = ThreeWayMerge::merge(['title' => 'A'], ['title' => 'B'], ['title' => 'B']);

    expect($result->conflicts)->toBe([])->and($result->taken)->toBe([]);
});

it('reports a field both sides changed differently as a conflict and leaves mine in place', function (): void {
    $result = ThreeWayMerge::merge(
        base: ['title' => 'A', 'price' => 10],
        mine: ['title' => 'Mine', 'price' => 10],
        theirs: ['title' => 'Theirs', 'price' => 12],
    );

    expect($result->conflicts)->toBe(['title'])
        ->and($result->taken)->toBe(['price'])
        ->and($result->data)->toBe(['title' => 'Mine', 'price' => 12]);
});

it('sees through the type noise forms add', function (mixed $a, mixed $b): void {
    expect(ThreeWayMerge::normalize($a))->toBe(ThreeWayMerge::normalize($b));
})->with([
    'numeric string and int' => ['12', 12],
    'decimal cast and typed number' => ['10.00', '10'],
    'float and int' => [12.0, 12],
    'empty string and null' => ['', null],
    'empty array and null' => [[], null],
    'bools and their form values' => [true, '1'],
    'enum and its value' => [Stage::Live, 'live'],
    'datetime picker and database' => ['2026-10-21T10:30', '2026-10-21 10:30:00'],
    'carbon and string' => [Carbon::parse('2026-10-21 10:30:00'), '2026-10-21 10:30:00'],
    'repeater items keyed by fresh uuids' => [
        ['0f8fad5b-d9cb-469f-a165-70867728950e' => ['name' => 'news']],
        ['7c9e6679-7425-40de-944b-e07fc1f90ae7' => ['name' => 'news']],
    ],
    'object key order' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]],
    'serialised datetime cast and form value' => ['2026-10-21T10:30:00.000000Z', '2026-10-21 10:30:00'],
    'one instant in two zones' => ['2026-10-21T12:30:00+02:00', '2026-10-21 10:30:00'],
    'a zero fraction' => ['2026-10-21 10:30:00.000', '2026-10-21 10:30:00'],
    'negative decimal and float' => ['-3.50', -3.5],
    'negative zero' => ['-0.0', 0],
    'a number input without its leading zero' => ['.5', '0.5'],
    'negative without its leading zero' => ['-.5', -0.5],
    'a float past ten places and its digits' => [1 / 3, '0.33333333333333331483'],
]);

it('still tells real differences apart', function (mixed $a, mixed $b): void {
    expect(ThreeWayMerge::normalize($a))->not->toBe(ThreeWayMerge::normalize($b));
})->with([
    'different numbers' => ['10', '10.5'],
    'same wall time in two zones' => ['2026-10-21T10:30:00+02:00', '2026-10-21 10:30:00'],
    'same time, another fraction' => ['2026-10-21 10:30:00.5', '2026-10-21 10:30:00'],
    'carbon in another zone' => [Carbon::parse('2026-10-21 10:30:00', 'Europe/Chisinau'), '2026-10-21 10:30:00'],
    'texts that start with the same date-time' => ['2026-10-28 10:00 called, no answer', '2026-10-28 10:00 called, nothing. Wrote back.'],
    'a phone number and the same digits' => ['+40123', '40123'],
    'a number with a space' => ['12 ', '12'],
    'exponent and its value' => ['1e3', '1000'],
    'long ids past float precision' => ['9007199254740993', '9007199254740992'],
    'two different invalid UTF-8 strings' => ["caf\xE9", "caf\xE8"],
    'zero and null' => [0, null],
    'leading zeros stay text' => ['007', '7'],
    'list order' => [['a', 'b'], ['b', 'a']],
    'repeater items in a new order' => [
        ['0f8fad5b-d9cb-469f-a165-70867728950e' => 'a', '7c9e6679-7425-40de-944b-e07fc1f90ae7' => 'b'],
        ['7c9e6679-7425-40de-944b-e07fc1f90ae7' => 'b', '0f8fad5b-d9cb-469f-a165-70867728950e' => 'a'],
    ],
    'false and null' => [false, null],
]);

it('lists the changed fields between two versions', function (): void {
    expect(ThreeWayMerge::changed(
        ['title' => 'A', 'price' => '10.00', 'status' => 'draft'],
        ['title' => 'B', 'price' => 10, 'status' => 'draft', 'tags' => ['x']],
    ))->toBe(['title', 'tags']);
});

it('keeps their edit to a note that starts with a date-time', function (): void {
    $note = '2026-10-28 10:00 called, no answer';

    $result = ThreeWayMerge::merge(['note' => $note], ['note' => $note], ['note' => $note.' Wrote back.']);

    expect($result->taken)->toBe(['note'])
        ->and($result->data['note'])->toBe($note.' Wrote back.');
});

it('keeps their edit to text that only looks like a number', function (string $before, string $after): void {
    $result = ThreeWayMerge::merge(['version' => $before], ['version' => $before], ['version' => $after]);

    expect($result->taken)->toBe(['version'])
        ->and($result->data['version'])->toBe($after)
        ->and(ThreeWayMerge::changed(['version' => $before], ['version' => $after], exact: true))->toBe(['version']);
})->with([
    'version with a trailing zero' => ['1.1', '1.10'],
    'whole version' => ['2.0', '2'],
    'leading dot' => ['.5', '0.5'],
]);

it('still sees the same number typed two ways on both sides as no conflict', function (): void {
    // A decimal cast fills "10.00"; you typed 10.5 and they saved 10.5, which the cast reads as "10.50".
    $result = ThreeWayMerge::merge(['price' => '10.00'], ['price' => '10.5'], ['price' => '10.50']);

    expect($result->conflicts)->toBe([])->and($result->taken)->toBe([])->and($result->data['price'])->toBe('10.5');
});

it('counts text you retyped as your change, even when it reads as the same number', function (): void {
    $result = ThreeWayMerge::merge(['version' => '1.1'], ['version' => '1.10'], ['version' => '2']);

    expect($result->conflicts)->toBe(['version'])->and($result->taken)->toBe([]);
});

it('still reads values the browser did not type through the form noise', function (mixed $base, mixed $mine): void {
    expect(ThreeWayMerge::untouched($base, $mine))->toBeTrue();
})->with([
    'integer refilled as text' => [12, '12'],
    'cleared text' => [null, ''],
    'decimal kept as a number' => ['10.00', 10],
    'toggle' => [true, '1'],
    'date-time without seconds' => ['2026-10-28 10:00:00', '2026-10-28T10:00'],
]);

it('keeps the form noise rules in exact comparisons, except for numbers in text', function (): void {
    expect(ThreeWayMerge::normalize(['0f8fad5b-d9cb-469f-a165-70867728950e' => 'a'], exact: true))
        ->toBe(ThreeWayMerge::normalize(['7c9e6679-7425-40de-944b-e07fc1f90ae7' => 'a'], exact: true))
        ->and(ThreeWayMerge::normalize('', exact: true))->toBe(ThreeWayMerge::normalize(null, exact: true))
        ->and(ThreeWayMerge::normalize(12, exact: true))->toBe(ThreeWayMerge::normalize('12', exact: true))
        ->and(ThreeWayMerge::normalize('1.10', exact: true))->not->toBe(ThreeWayMerge::normalize('1.1', exact: true));
});

it('compares text that only looks like a zoned date-time as text', function (string $text): void {
    expect(ThreeWayMerge::normalize($text))->toBe(json_encode($text))
        ->and(ThreeWayMerge::changed(['note' => $text], ['note' => $text.'!'], exact: true))->toBe(['note']);
})->with(['2026-13-01T10:30Z', '2026-10-28T25:30Z', '2026-10-28 99:99+02:00']);

it('counts a leaf you retyped inside an array as your change', function (): void {
    $result = ThreeWayMerge::merge(['m' => ['a' => '1.1', 'b' => 'x']], ['m' => ['a' => '1.10', 'b' => 'x']], ['m' => ['a' => '1.1', 'b' => 'y']]);

    expect($result->conflicts)->toBe(['m'])->and($result->taken)->toBe([]);
});

it('still reads array items the browser did not retype as unchanged', function (): void {
    expect(ThreeWayMerge::untouched([1, 2], ['1', '2']))->toBeTrue()
        ->and(ThreeWayMerge::untouched(['price' => 10.0, 'on' => true], ['price' => 10, 'on' => true]))->toBeTrue()
        ->and(ThreeWayMerge::untouched(['0f8fad5b-d9cb-469f-a165-70867728950e' => 'a.jpg'], ['7c9e6679-7425-40de-944b-e07fc1f90ae7' => 'a.jpg']))->toBeTrue();
});

it('reads a rich document as the editor writes it back once loaded as the same document', function (): void {
    $text = fn (string $text): array => ['type' => 'text', 'text' => $text];
    $loaded = ['type' => 'doc', 'content' => [
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$text('One')]]]],
            ['type' => 'listItem', 'content' => [$text('Two')]],
            ['type' => 'listItem', 'content' => [['type' => 'bulletList', 'content' => []]]],
        ]],
    ]];
    $written = ['type' => 'doc', 'content' => [
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$text('One')]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$text('Two')]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []], ['type' => 'bulletList', 'content' => []]]],
        ]],
    ]];

    expect(ThreeWayMerge::untouched($loaded, $written))->toBeTrue()
        ->and(ThreeWayMerge::normalize($written))->not->toBe(ThreeWayMerge::normalize([...$written, 'content' => [['type' => 'paragraph', 'content' => [$text('Other')]]]]));
});

it('keeps a lead or another block node in a list item as a block', function (string $block): void {
    $text = ['type' => 'text', 'text' => 'Intro'];
    $stored = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [
        ['type' => 'listItem', 'content' => [['type' => $block, 'content' => [['type' => 'paragraph', 'content' => [$text]]]]]],
    ]]]];
    $written = ['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [
        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []], ['type' => $block, 'content' => [['type' => 'paragraph', 'content' => [$text]]]]]],
    ]]]];

    expect(ThreeWayMerge::untouched($stored, $written))->toBeTrue();
})->with(['lead', 'someEmbed']);
