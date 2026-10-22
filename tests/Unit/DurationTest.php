<?php

use App\Support\Duration;

it('shows minutes as hours and minutes', function (int $minutes, string $long, string $short) {
    expect(Duration::minutes($minutes))->toBe($long)
        ->and(Duration::minutes($minutes, short: true))->toBe($short);
})->with([
    [314, '5 hr 14 min', '5h 14m'],
    [60, '1 hr', '1h'],
    [120, '2 hr', '2h'],
    [45, '45 min', '45m'],
    [61, '1 hr 1 min', '1h 1m'],
    [0, '0 min', '0m'],
]);
