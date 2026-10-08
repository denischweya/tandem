<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Required, not optional. These tests insert rows with fixed email addresses. Without a
// clean database each run, the SECOND run would throw on the FIRST insert and the
// "rejects a duplicate" tests would pass for entirely the wrong reason.
uses(RefreshDatabase::class);

function insertUser(string $email): string
{
    $id = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $id,
        'email' => $email,
        'password_hash' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function insertPlan(string $ownerId): string
{
    $id = (string) Str::uuid7();

    DB::table('plans')->insert([
        'id' => $id,
        'owner_id' => $ownerId,
        'title' => 'Dinner',
        'timezone' => 'Africa/Nairobi',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('rejects a duplicate user email at the database level', function (): void {
    insertUser('denis@example.com');

    expect(fn () => insertUser('denis@example.com'))
        ->toThrow(QueryException::class);
});

it('rejects a mixed-case user email at the database level', function (): void {
    // users_email_lowercase CHECK, added alongside users_email_unique: Email::fromString()
    // is the only place that lowercases, so nothing stops a write path that bypasses it
    // (such as this test's own raw DB::table()->insert()) from storing a twin that splits
    // every lowercase lookup. The CHECK, not a functional unique index, is what makes that
    // impossible at the database level - see 2026_10_06_000100_create_identity_tables.php.
    expect(fn () => insertUser('Alice@Example.com'))
        ->toThrow(QueryException::class);
});

it('rejects a mixed-case plan invite email at the database level', function (): void {
    $owner = insertUser('invite-owner@example.com');
    $plan = insertPlan($owner);

    expect(fn () => DB::table('plan_invites')->insert([
        'id' => (string) Str::uuid7(),
        'plan_id' => $plan,
        'email' => 'Bob@Example.com',
        'token_hash' => (string) Str::uuid7(),
        'role' => 'member',
        'expires_at' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects the same user joining a plan twice at the database level', function (): void {
    $owner = insertUser('owner@example.com');
    $plan = insertPlan($owner);

    $row = fn () => [
        'id' => (string) Str::uuid7(),
        'plan_id' => $plan,
        'user_id' => $owner,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('plan_members')->insert($row());

    expect(fn () => DB::table('plan_members')->insert($row()))
        ->toThrow(QueryException::class);
});

it('refuses to delete a user who still owns a plan', function (): void {
    $owner = insertUser('stubborn@example.com');
    insertPlan($owner);

    expect(fn () => DB::table('users')->where('id', $owner)->delete())
        ->toThrow(QueryException::class);
});

// Spec §7's eight unique constraints. All eight already exist in the live database;
// these tests exist to catch a future migration regression, not to establish correctness.
//
// Each factory returns a row WITHOUT an `id`: the test below assigns a fresh uuid7 to
// each of the two inserts. Sharing one fixed `id` across both inserts (tried first) made
// every dataset pass for the wrong reason - the second insert fails on the primary key
// before Postgres ever reaches the unique index under test. Confirmed by temporarily
// dropping the devices(user_id, install_id) unique constraint from the migration: with a
// shared `id` the test still passed; with a fresh `id` per insert it failed as expected.
it('enforces the eight spec §7 unique constraints at the database level', function (string $table, callable $rowFactory) {
    $row = $rowFactory();

    DB::table($table)->insert(['id' => (string) Str::uuid7(), ...$row]);

    expect(fn () => DB::table($table)->insert(['id' => (string) Str::uuid7(), ...$row]))
        ->toThrow(QueryException::class);
})->with([
    'users.email' => ['users', function () {
        return [
            'email' => 'duplicate-'.Str::lower(Str::random(8)).'@example.com',
            'password_hash' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'user_profiles.user_id' => ['user_profiles', function () {
        $userId = insertUser('profile-'.Str::lower(Str::random(8)).'@example.com');

        return [
            'user_id' => $userId,
            'display_name' => 'Dup Profile',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'devices(user_id, install_id)' => ['devices', function () {
        $userId = insertUser('device-'.Str::lower(Str::random(8)).'@example.com');

        return [
            'user_id' => $userId,
            'install_id' => 'install-fixed',
            'platform' => 'ios',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'refresh_tokens.token_hash' => ['refresh_tokens', function () {
        $userId = insertUser('refresh-'.Str::lower(Str::random(8)).'@example.com');

        return [
            'user_id' => $userId,
            'token_hash' => 'fixed-token-hash',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'password_reset_tokens.token_hash' => ['password_reset_tokens', function () {
        $userId = insertUser('reset-'.Str::lower(Str::random(8)).'@example.com');

        return [
            'user_id' => $userId,
            'token_hash' => 'fixed-reset-hash',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'plan_members(plan_id, user_id)' => ['plan_members', function () {
        $owner = insertUser('plan-member-'.Str::lower(Str::random(8)).'@example.com');
        $plan = insertPlan($owner);

        return [
            'plan_id' => $plan,
            'user_id' => $owner,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'plan_invites.token_hash' => ['plan_invites', function () {
        $owner = insertUser('plan-invite-'.Str::lower(Str::random(8)).'@example.com');
        $plan = insertPlan($owner);

        return [
            'plan_id' => $plan,
            'token_hash' => 'fixed-invite-hash',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
    'idempotency_keys(user_id, key)' => ['idempotency_keys', function () {
        $userId = insertUser('idempotency-'.Str::lower(Str::random(8)).'@example.com');

        return [
            'user_id' => $userId,
            'key' => 'fixed-key',
            'endpoint' => '/api/v1/plans',
            'request_hash' => 'fixed-request-hash',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }],
]);

it('stores timestamps as timestamptz, not timestamp', function (): void {
    // No exclusion list: every table this project migrates uses timestampsTz()/timestampTz(),
    // and failed_jobs.failed_at (the last holdout - it shipped as a naive timestamp() because
    // it is copied from Laravel's own stub) was fixed alongside this test. cache, cache_locks,
    // jobs, job_batches and the framework's own migrations table have no timestamp-typed
    // column at all (they use bigInteger/unsignedInteger "timestamps"), so they were never a
    // real exclusion - keeping them listed only pre-authorised a future naive-timestamp
    // column on a table this project owns. sessions does not exist in this schema.
    $types = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type')
        ->whereIn('table_schema', ['public'])
        ->whereIn('data_type', ['timestamp without time zone'])
        ->get();

    expect($types)->toBeEmpty(
        'These columns are timestamp without time zone and violate the UTC invariant: '
        .$types->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->implode(', ')
    );
});

it('stores every timestamptz column at precision 6, not the framework default of 0', function (): void {
    // Builder::$defaultTimePrecision is 0, and Postgres *rounds* rather than truncates:
    // '...12:00:00.6+00'::timestamptz(0) becomes '...12:00:01+00'. A column created without
    // an explicit precision can therefore store a value in the future relative to when it
    // was written, and two rows written microseconds apart in one transaction can tie on
    // ORDER BY created_at. No exclusion list, same reasoning as the sweep above: every
    // timestamptz column this project creates is already explicit at precision 6.
    $wrongPrecision = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'datetime_precision')
        ->whereIn('table_schema', ['public'])
        ->where('data_type', 'timestamp with time zone')
        ->where('datetime_precision', '!=', 6)
        ->get();

    expect($wrongPrecision)->toBeEmpty(
        'These timestamptz columns are not at precision 6: '
        .$wrongPrecision->map(fn ($c) => "{$c->table_name}.{$c->column_name} (precision {$c->datetime_precision})")->implode(', ')
    );
});
