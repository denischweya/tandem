<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('returns the authenticated user', function (): void {
    $user = User::factory()->hasProfile(['display_name' => 'Ada Lovelace', 'timezone' => 'Europe/London'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);

    [, , $access] = signIn($user);

    withToken($access)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.display_name', 'Ada Lovelace')
        ->assertJsonPath('data.timezone', 'Europe/London');
});

it('requires authentication', function (): void {
    getJson('/api/v1/me')->assertUnauthorized();
});

it('rejects an expired access token', function (): void {
    $user = User::factory()->hasProfile()->create();
    $token = $user->createToken('api', ['*'], now()->subSecond());

    withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

it('never exposes the password hash or any token', function (): void {
    $user = User::factory()->hasProfile()->create(['password_hash' => Hash::make('correct horse battery staple')]);
    [, , $access] = signIn($user);

    $body = withToken($access)->getJson('/api/v1/me')->content();

    expect($body)->not->toContain('password');
    expect($body)->not->toContain('$2y$');
    expect($body)->not->toContain('token');
});
