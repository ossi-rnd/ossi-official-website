<?php

use App\Support\EmployeeNumber;
use App\Support\PhilippineFormats;

it('puts back the dashes left out of an employee number, and leaves older numbers alone', function (string $typed, string $saved) {
    expect(EmployeeNumber::normalize($typed))->toBe($saved);
})->with([
    ['260627pam0950', '260627PAM-0950'],
    ['260627PAM 0950 rh', '260627PAM-0950-RH'],
    ['260627PAM-0950-RH', '260627PAM-0950-RH'],
    ['emp-1001', 'EMP-1001'],
    ['240601-sec-1001', '240601-SEC-1001'],
]);

it('makes a mobile readable however it was typed', function (string $typed) {
    expect(PhilippineFormats::readableMobile($typed))->toBe('0917 123 4567');
})->with(['09171234567', '0917-123-4567', '+63 917 123 4567', '639171234567', '9171234567']);

it('accepts a mobile or a landline, and nothing else, as a phone number', function () {
    expect(PhilippineFormats::phoneNumber('+63 917 123 4567'))->toBe('0917 123 4567')
        ->and(PhilippineFormats::phoneNumber('(02)  8123 4567'))->toBe('(02) 8123 4567')
        ->and(PhilippineFormats::phoneNumber('046 123 4567'))->toBe('046 123 4567')
        ->and(PhilippineFormats::phoneNumber('8123 4567'))->toBe('8123 4567')
        ->and(PhilippineFormats::phoneNumber('0917 123 456'))->toBeNull()   // a mobile missing a digit
        ->and(PhilippineFormats::phoneNumber('0917 CALL ME'))->toBeNull()
        ->and(PhilippineFormats::phoneNumber('12345'))->toBeNull();
});

it('takes names with letters and . , \' - only', function (string $name, bool $ok) {
    expect(preg_match(PhilippineFormats::NAME_PATTERN, PhilippineFormats::name($name)) === 1)->toBe($ok);
})->with([
    ['Ma. Cristina Dela Cruz-Reyes Jr.', true],
    ["Jose O'Neil Peñaflor", true],
    ['  Juan   Dela Cruz ', true],
    ['Juan 2', false],
    ['@juan', false],
    ['', false],
]);
