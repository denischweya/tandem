<?php

declare(strict_types=1);

use App\Domain\Shared\PageCursor;
use App\Http\Rules\ValidCursor;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Illuminate\Contracts\Validation\ValidationRule::validate()'s $fail parameter is PHPDoc-typed
 * as Closure(string, ?string=): PotentiallyTranslatedString, not Closure(string): void - the
 * real validator's $fail is chainable (->translate()), so its return value is never void. A
 * void-returning closure is a `void` vs `PotentiallyTranslatedString` return-type mismatch at
 * level 8 (argument.type), measured rather than assumed from the brief's own closure shape.
 *
 * PotentiallyTranslatedString's own source leaves its $translator constructor parameter
 * untyped, but Larastan's stub for it narrows that to Illuminate\Contracts\Translation\
 * Translator, so `null` is rejected even though the real constructor would accept it. The
 * stub below implements the interface and is never called - ->translate() and
 * ->translateChoice() are the only methods that touch it, and neither runs in this suite - so
 * constructing it needs no booted container, staying inside the Unit suite's "boots no
 * application" rule, same as instantiating Illuminate\Http\Request directly does elsewhere in
 * this new tests/Unit/Http/ subtree.
 *
 * @param  array<int, string>  $failures
 */
function recordingFailClosure(array &$failures): Closure
{
    $translator = new class implements Translator
    {
        /** @param array<array-key, string> $replace */
        public function get($key, array $replace = [], $locale = null)
        {
            throw new LogicException('not used: this test never calls translate()');
        }

        /**
         * @param  Countable|int|float|array<array-key, mixed>  $number
         * @param  array<array-key, string>  $replace
         */
        public function choice($key, $number, array $replace = [], $locale = null)
        {
            throw new LogicException('not used: this test never calls translateChoice()');
        }

        public function getLocale()
        {
            throw new LogicException('not used: this test never calls translate()');
        }

        public function setLocale($locale)
        {
            throw new LogicException('not used: this test never calls translate()');
        }
    };

    return function (string $message, ?string $translation = null) use (&$failures, $translator): PotentiallyTranslatedString {
        $failures[] = $translation ?? $message;

        return new PotentiallyTranslatedString($message, $translator);
    };
}

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
