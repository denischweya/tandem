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

it('stores timestamps as timestamptz, not timestamp', function (): void {
    $types = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type')
        ->whereIn('table_schema', ['public'])
        ->whereIn('data_type', ['timestamp without time zone'])
        ->whereNotIn('table_name', ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'sessions'])
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
    // ORDER BY created_at. Same exclusion list as the sweep above - those are vendor tables
    // this project does not migrate.
    $wrongPrecision = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'datetime_precision')
        ->whereIn('table_schema', ['public'])
        ->where('data_type', 'timestamp with time zone')
        ->whereNotIn('table_name', ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'sessions'])
        ->where('datetime_precision', '!=', 6)
        ->get();

    expect($wrongPrecision)->toBeEmpty(
        'These timestamptz columns are not at precision 6: '
        .$wrongPrecision->map(fn ($c) => "{$c->table_name}.{$c->column_name} (precision {$c->datetime_precision})")->implode(', ')
    );
});
