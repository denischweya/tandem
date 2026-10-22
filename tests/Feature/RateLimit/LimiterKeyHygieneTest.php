<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Spec §13.5's trap, as a gate rather than as a comment.
 *
 * RateLimiter::clear('plans-create') is a no-op. ThrottleRequests stores its bucket under
 * md5($limiterName.$limit->key) - ThrottleRequests::$shouldHashKeys defaults true and
 * ThrottleRequests::handleRequestUsingNamedLimiter() is where that key is built - while
 * RateLimiter::clear($key) applies only cleanRateLimiterKey() and no md5 at all, so the bare
 * name addresses a bucket nothing ever writes. attempts(), remaining(), tooManyAttempts() and
 * availableIn() are worse than clear(), because they read as assertions: attempts('plans-create')
 * returns 0 for ever.
 *
 * This was measured independently in Wave 1a tasks 3, 4 and 6, and in task 6 a clear() call
 * "READS AS LOAD-BEARING between the two requests" of the test it sat in while doing nothing at
 * all - which made that task's highest-weight test pass for the wrong reason. The one live call
 * left in the suite, RegisterTest's beforeEach, is deleted in the same change as this file.
 *
 * Nothing is needed in place of a reset: phpunit.xml pins CACHE_STORE=array and the application
 * is rebuilt per test, so limiter state never crosses a test boundary. Within ONE test the array
 * store does persist, which is why a limiter test must exhaust its budget inside a single it()
 * block rather than splitting setup from assertion.
 *
 * If a test ever genuinely needs to pre-fill or read a limiter bucket there are two honest ways,
 * and neither is spelled RateLimiter::<method>(<limiter name>): drive the real route until it
 * answers 429, or compute the real composite key - md5($limiterName.$key) - and use the Cache
 * facade with it.
 *
 * Scope, and its limit stated rather than implied: this scans for the FACADE form. A test that
 * resolved Illuminate\Cache\RateLimiter out of the container and called ->clear() on the instance
 * would pass. Nothing in the suite does that, and the facade is the form every brief has reached
 * for.
 *
 * No uses(RefreshDatabase::class): this file reads files and touches no table. The precedent is
 * tests/Feature/Contract/OpenApiContractTest.php, which does the same.
 */
it('finds every spelling of a forbidden call and no comment, no limiter() and no other class', function (): void {
    // A nowdoc, not a heredoc and not a comment, for a measured reason. This file is itself
    // inside the scan in the second test below, and a nowdoc body tokenises as
    // T_START_HEREDOC + T_ENCAPSED_AND_WHITESPACE + T_END_HEREDOC - never as the three-token
    // sequence the scanner matches - so the fixture cannot make the gate fail against itself.
    // A nowdoc rather than a heredoc because '$' and '\' in the body must stay literal.
    $fixture = <<<'PHP'
<?php

use Illuminate\Support\Facades\RateLimiter;

// RateLimiter::clear('auth-register') reads as load-bearing and does nothing.
/** @see RateLimiter::attempts() for the unhashed-key hazard. */
RateLimiter::clear('plans-create');
\RateLimiter::attempts('plans-create');
Illuminate\Support\Facades\RateLimiter::remaining('plan-invites', 50);
RateLimiter   ::   hit('invite-lookup', 3600);
RateLimiter /* the bare name is not the real key */ ::clear('plans-create');
RateLimiter::limiter('invite-lookup');
RateLimiter::for('plans-create', fn () => null);
LoginRateLimiter::clear('auth-login:ip:127.0.0.1');
RateLimiter::Clear('plans-create');
PHP;

    // Eleven lines carry a RateLimiter-shaped token in call position (5, 6, 7, 8, 9, 10, 11, 12,
    // 13, 14, 15); six are findings and five are not, and each of the five non-findings is a
    // distinct way this gate could have been wrong:
    //   line 5  - a line comment is one T_COMMENT token, so the text inside it is never the
    //             three-token sequence. This is why the three files that explain in prose why
    //             they do NOT reset a limiter stay clean with no special case.
    //   line 6  - the same for a T_DOC_COMMENT.
    //   line 12 - limiter() takes a limiter NAME. It is the right call and LimiterRegistryTest
    //             makes it.
    //   line 13 - so does for(). Forbidding it would forbid registering a limiter at all.
    //   line 14 - LoginRateLimiter is a different class. A substring match on
    //             'RateLimiter::clear(' would have flagged it, and app-layer limiter builds its
    //             own literal keys, so clearing one of those is correct.
    // Of the six findings, three are spellings a single hardcoded needle would have missed:
    //   line 8  - the root facade alias, \RateLimiter::, which this project's own phpstan rules
    //             exist because six of eight such aliases evaded a name-based arch check.
    //   line 9  - the fully qualified name.
    //   lines 10, 11 - whitespace and a block comment between the three tokens.
    // And the sixth finding proves a property none of the other five do:
    //   line 15 - RateLimiter::Clear(), capital C. PHP method dispatch is case-insensitive, so
    //             this is a real, working call that the plain `in_array($method, $forbidden,
    //             true)` form misses outright (B35a). strtolower() on both sides is what catches
    //             it, and no other fixture line exercises that comparison.
    expect(rateLimiterKeyCallsIn($fixture))->toBe([
        'RateLimiter::clear() on line 7',
        'RateLimiter::attempts() on line 8',
        'RateLimiter::remaining() on line 9',
        'RateLimiter::hit() on line 10',
        'RateLimiter::clear() on line 11',
        'RateLimiter::Clear() on line 15',
    ], 'six findings; the two comments, limiter(), for() and LoginRateLimiter are not findings');
});

it('never passes a limiter name where the cache API wants a cache key', function (): void {
    $findings = [];
    $scanned = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned[] = $file->getRelativePathname();

        foreach (rateLimiterKeyCallsIn($file->getContents()) as $call) {
            $findings[] = $file->getRelativePathname().': '.$call;
        }
    }

    // Two files that certainly exist, one at the root of tests/ and one three directories down,
    // so that a traversal which silently found nothing cannot pass the assertion below by having
    // scanned an empty list. Without this the whole gate would be green if base_path('tests')
    // were ever wrong.
    expect($scanned)->toContain('Pest.php', 'Feature/Auth/RegisterTest.php');

    expect($findings)->toBe(
        [],
        'the real bucket is md5($limiterName.$limit->key), so a bare limiter name clears and reads nothing: '
        .'exhaust the route to a real 429, or hash the key yourself and use the Cache facade',
    );
});
