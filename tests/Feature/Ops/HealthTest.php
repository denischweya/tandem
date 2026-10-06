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
    $body = getJson('/ready')->getContent();

    expect($body)
        ->not->toContain('127.0.0.1')
        ->not->toContain('5432')
        ->not->toContain('postgres')
        ->not->toContain('tandem_password');
});
