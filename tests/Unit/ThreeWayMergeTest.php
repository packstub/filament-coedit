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
    'negative decimal and float' => ['-3.50', -3.5],
    'negative zero' => ['-0.0', 0],
]);

it('still tells real differences apart', function (mixed $a, mixed $b): void {
    expect(ThreeWayMerge::normalize($a))->not->toBe(ThreeWayMerge::normalize($b));
})->with([
    'different numbers' => ['10', '10.5'],
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
