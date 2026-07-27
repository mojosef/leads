<?php

use mojosef\Leads\Support\PhoneNumber;

it('normalises UK numbers to E.164', function (?string $input, ?string $expected) {
    expect(PhoneNumber::toE164($input))->toBe($expected);
})->with([
    'leading zero' => ['07123456789', '+447123456789'],
    'spaces and zero' => ['07123 456 789', '+447123456789'],
    'hyphens' => ['07123-456-789', '+447123456789'],
    'parentheses' => ['(07123) 456789', '+447123456789'],
    'already international' => ['+447123456789', '+447123456789'],
    'international with spaces' => ['+44 7123 456789', '+447123456789'],
    'double zero prefix' => ['00447123456789', '+447123456789'],
    'bare country code' => ['447123456789', '+447123456789'],
    'empty string' => ['', null],
    'whitespace only' => ['   ', null],
    'null' => [null, null],
]);

it('honours a non-UK country calling code', function () {
    expect(PhoneNumber::toE164('0412345678', '61'))->toBe('+61412345678');
});
