<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

it('reports liveness without touching dependencies', function (): void {
    getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('reports readiness of each dependency', function (): void {
    getJson('/ready')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'checks' => ['database' => true, 'redis' => true, 'queue' => true],
        ]);
});

it('reports degraded without leaking why', function (): void {
    config(['database.redis.default.port' => 1]);

    $response = getJson('/ready');
    $response->assertStatus(503)->assertJson([
        'status' => 'degraded',
        'checks' => ['database' => true, 'redis' => false, 'queue' => false],
    ]);

    $body = (string) $response->getContent();
    foreach (['127.0.0.1', '6379', 'pgsql', 'tandem', 'password', 'Connection refused', 'SQLSTATE'] as $needle) {
        expect(str_contains($body, $needle))->toBeFalse("Degraded response leaked: {$needle}");
    }
});

it('does not leak infrastructure detail in readiness', function (): void {
    $body = (string) getJson('/ready')->getContent();

    // str_contains(...)->toBeFalse() rather than Pest's ->not chain: PHPStan level 8
    // rejects `->not` as an undefined property on Expectation, and this project does not
    // suppress PHPStan findings.
    //
    // These needles are host names, ports and credential values - things that can only
    // appear by leaking connection detail. The dependency NAMES in `checks` (database,
    // redis, queue) are the endpoint's deliberate public contract and must NOT be treated
    // as leaks: asserting the body omits "redis" while also asserting it contains a key
    // named "redis" is unsatisfiable.
    foreach (['127.0.0.1', 'localhost', '5432', '6379', '1025', 'pgsql', 'tandem', 'password'] as $needle) {
        expect(str_contains($body, $needle))
            ->toBeFalse("Readiness response leaked infrastructure detail: {$needle}");
    }
});
