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

it('does not leak infrastructure detail in readiness', function (): void {
    $body = (string) getJson('/ready')->getContent();

    // str_contains(...)->toBeFalse() rather than Pest's ->not chain: PHPStan level 8
    // rejects `->not` as an undefined property on Expectation, and this project does not
    // suppress PHPStan findings.
    foreach (['127.0.0.1', '5432', 'postgres', 'tandem', 'redis'] as $needle) {
        expect(str_contains($body, $needle))
            ->toBeFalse("Readiness response leaked infrastructure detail: {$needle}");
    }
});
