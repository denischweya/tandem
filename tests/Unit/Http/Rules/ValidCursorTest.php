<?php

declare(strict_types=1);

use App\Domain\Shared\PageCursor;
use App\Http\Rules\ValidCursor;

// recordingFailClosure() moved to tests/Pest.php in fix round 1: a file-scope function is
// process-global the moment Pest loads it, and this one's name was generic enough that a
// future ValidationRule unit test could plausibly redeclare it, which is a fatal, not a test
// failure. See its docblock there for why the closure needs this shape at all.
it('accepts a cursor this API issued', function (): void {
    $failures = [];
    $encoded = PageCursor::fromKeys(
        new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00'),
        '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
    )->toString();

    (new ValidCursor)->validate('cursor', $encoded, recordingFailClosure($failures));

    expect($failures)->toBe([], 'a cursor the codec produced must validate');
});

it('refuses a malformed cursor with one message, so the endpoint answers 422 and never 500', function (): void {
    $failures = [];

    (new ValidCursor)->validate('cursor', 'not-a-cursor', recordingFailClosure($failures));

    expect($failures)->toBe(
        ['The :attribute is not a valid page cursor.'],
        'the rule converts the value object InvalidArgumentException into a validation failure, '
        .'exactly as ValidEmail does for Email::fromString()'
    );
});

it('refuses a non-string value without reaching the codec', function (): void {
    $failures = [];

    (new ValidCursor)->validate('cursor', ['an', 'array'], recordingFailClosure($failures));

    expect($failures)->toBe(
        ['The :attribute is not a valid page cursor.'],
        'an array arrives from ?cursor[]=x; PageCursor::fromString() is typed string, so the '
        .'guard has to come first or the rule raises a TypeError, which is a 500'
    );
});
