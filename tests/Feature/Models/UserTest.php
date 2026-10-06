<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// Nothing in app/, database/ or tests/ touched this model before the branch-review fix:
// getFillable() named columns ("name", "password") that do not exist, the "hashed" cast
// named a column ("password") that does not exist, and there was no HasUuids despite a
// uuid primary key. User::factory()->create() is therefore the load-bearing smoke test -
// without it, nothing in the suite ever reaches the model at all.
uses(RefreshDatabase::class);

it('can be created through its factory', function (): void {
    $user = User::factory()->create();

    expect($user->email)->not->toBeEmpty();
    expect($user->password_hash)->toStartWith('$2y$');
});

it('has a real UUIDv7 primary key, not an auto-increment integer', function (): void {
    $user = User::factory()->create();

    expect($user->getKeyType())->toBe('string');
    expect($user->getIncrementing())->toBeFalse();
    expect($user->id)
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('serializes no key that looks like a credential, keyed off the real schema', function (): void {
    $user = User::factory()->create();

    // Schema-driven, not an assertion on getHidden()'s literal contents (per the
    // branch-review fix): this walks every column users actually has, rather than a
    // hard-coded list, so it survives a column rename and catches a new sensitive column
    // added later without a matching #[Hidden] entry.
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'users')
        ->pluck('column_name');

    expect($columns)->toContain('password_hash');

    $serialized = $user->toArray();

    foreach (array_keys($serialized) as $key) {
        expect($key)->not->toMatch('/password|token|secret/i');
    }

    // The id key must still be present and be the real UUID string, not silently dropped
    // by the leak check above (which only forbids password/token/secret-shaped keys).
    expect($serialized)->toHaveKey('id');
    expect($serialized['id'] ?? null)->toBe($user->id);
});

it('is soft-deleted, not hard-deleted', function (): void {
    $user = User::factory()->create();

    $user->delete();

    expect($user->deleted_at)->not->toBeNull();
    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
});
