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
