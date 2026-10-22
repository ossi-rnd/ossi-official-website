<?php

use App\Support\GpsAccuracy;

it('puts GPS accuracy into words', function (?int $meters, ?string $label, ?string $tone) {
    $result = GpsAccuracy::describe($meters);

    expect($result['label'] ?? null)->toBe($label)->and($result['tone'] ?? null)->toBe($tone);
})->with([
    'unknown' => [null, null, null],
    'precise' => [8, 'Precise location', 'blue'],
    'good' => [35, 'Good location', 'gray'],
    'rough' => [95, 'Rough location, within ~95 m', 'amber'],
    'weak' => [300, 'Weak signal, may be off by 300 m', 'red'],
]);
