<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/** @return array{User, string} */
function authenticatedUser(): array
{
    $user = User::factory()->hasProfile(['display_name' => 'Before', 'timezone' => 'UTC', 'locale' => 'en'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);

    [, , $access] = signIn($user);

    return [$user, $access];
}

it('updates the display name and the timezone', function (): void {
    [$user, $access] = authenticatedUser();

    withToken($access)->patchJson('/api/v1/me', [
        'display_name' => 'Ada Lovelace',
        'timezone' => 'Pacific/Kiritimati',
    ])->assertOk()
        ->assertJsonPath('data.display_name', 'Ada Lovelace')
        ->assertJsonPath('data.timezone', 'Pacific/Kiritimati');

    expect($user->refresh()->profile->timezone)->toBe('Pacific/Kiritimati');
});

it('leaves omitted fields alone', function (): void {
    [$user, $access] = authenticatedUser();

    withToken($access)->patchJson('/api/v1/me', ['display_name' => 'Ada Lovelace'])->assertOk();

    // PATCH, not PUT: an omitted field means "unchanged", never "clear this".
    expect($user->refresh()->profile->timezone)->toBe('UTC');
    expect($user->refresh()->profile->locale)->toBe('en');
});

it('normalizes whitespace in the display name through the value object', function (): void {
    [$user, $access] = authenticatedUser();

    withToken($access)->patchJson('/api/v1/me', ['display_name' => '  Ada   Lovelace  '])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Ada Lovelace');
});

it('rejects a non-IANA timezone', function (): void {
    [, $access] = authenticatedUser();

    withToken($access)->patchJson('/api/v1/me', ['timezone' => 'Mars/Olympus_Mons'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('timezone');
});

it('rejects a display name longer than eighty characters', function (): void {
    [, $access] = authenticatedUser();

    withToken($access)->patchJson('/api/v1/me', ['display_name' => str_repeat('a', 81)])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('display_name');
});

it('rejects an empty display name', function (): void {
    [, $access] = authenticatedUser();

    withToken($access)->patchJson('/api/v1/me', ['display_name' => '   '])
        ->assertStatus(422);
});

it('does not let a client change its own email or verification state', function (): void {
    // Starting from an unverified user, not authenticatedUser()'s default (already verified):
    // sending email_verified: true against an already-verified user would pass whether or not
    // the field is read at all, which asserts nothing about the half of this test's name that
    // concerns verification state.
    $user = User::factory()->unverified()
        ->hasProfile(['display_name' => 'Before', 'timezone' => 'UTC', 'locale' => 'en'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);

    [, , $access] = signIn($user);

    withToken($access)->patchJson('/api/v1/me', [
        'email' => 'someone-else@example.test',
        'email_verified' => true,
        'email_verified_at' => now()->toISOString(),
    ])->assertOk();

    // Neither field is in the request's rules, so neither reaches the use case. Changing an
    // email needs a re-verification flow, which no cycle has specified yet, and verification
    // state is never client-supplied - the user created above is still unverified afterwards.
    expect($user->refresh()->email)->not->toBe('someone-else@example.test');
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('requires authentication', function (): void {
    patchJson('/api/v1/me', ['display_name' => 'Ada'])->assertUnauthorized();
});
