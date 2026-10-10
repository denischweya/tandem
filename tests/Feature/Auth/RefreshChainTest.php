<?php

declare(strict_types=1);

use App\Infrastructure\Auth\RefreshTokenRotator;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * revokeChainFrom() exercised directly rather than through POST /auth/refresh.
 *
 * The behaviour under test is what happens to a chain LONGER than any constant bound, and
 * building one through the endpoint would mean a thousand real HTTP round trips. The rows are
 * what the method reads, so they are what the test writes: one bulk insert, linked by
 * replaced_by_id exactly as rotate() links them.
 */

/**
 * Inserts $length linked refresh_tokens rows for $user and returns the head.
 *
 * Every row but the last carries revoked_at, which is what a real chain looks like: rotate()
 * revokes as it replaces, so only the live tail has revoked_at NULL. That makes the tail the
 * only row whose revocation is observable, which is exactly the row a truncated walk left
 * behind.
 */
function linkedChain(User $user, int $length): RefreshToken
{
    $ids = [];
    for ($i = 0; $i < $length; $i++) {
        $ids[] = (string) Str::uuid7();
    }

    $rows = [];
    foreach ($ids as $i => $id) {
        $last = $i === $length - 1;
        $rows[] = [
            'id' => $id,
            'user_id' => $user->id,
            'device_id' => null,
            'token_hash' => hash('sha256', 'chain-'.$i.'-'.$id),
            'expires_at' => now()->addDays(30),
            'revoked_at' => $last ? null : now()->subMinute(),
            'replaced_by_id' => $last ? null : $ids[$i + 1],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // Chunked, because Postgres caps a statement at 65535 bound parameters and these rows
    // carry nine columns each.
    foreach (array_chunk($rows, 500) as $chunk) {
        RefreshToken::query()->insert($chunk);
    }

    return RefreshToken::query()->findOrFail($ids[0]);
}

it('revokes the live tail of a chain longer than any fixed bound', function (): void {
    $user = User::factory()->hasProfile()->create();

    // 1001 links: one past the literal 1000-hop guard this method used to carry. Spec §9.1.1
    // gives Cycle 1 no absolute session lifetime deliberately, so a rotating session is
    // immortal - at a 15-minute access TTL an active client produces 20-96 rotations a day and
    // reaches this length in weeks to months. Past the old bound a replay revoked the first
    // 1000 rows and left the LIVE TAIL ACTIVE, silently: a detected theft that disabled
    // nothing, which is the exact outcome the method exists to prevent.
    $head = linkedChain($user, 1001);
    $tailId = RefreshToken::query()->where('user_id', $user->id)->whereNull('replaced_by_id')->sole()->id;

    $revoked = app(RefreshTokenRotator::class)->revokeChainFrom($head);

    expect($revoked)->toBe(1);
    expect(RefreshToken::query()->findOrFail($tailId)->revoked_at)->not->toBeNull();
    expect(RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('terminates and says so when a chain points back at itself', function (): void {
    // The only thing a bound ever protected against, and it cannot arise from rotate() -
    // replaced_by_id always names a row created later. Reachable only by direct database
    // manipulation or a future bug, which is why exhausting the bound is now treated as
    // evidence of corruption and logged, rather than as the ordinary fate of an old account.
    $user = User::factory()->hasProfile()->create();

    $a = RefreshToken::factory()->create(['user_id' => $user->id, 'revoked_at' => null]);
    $b = RefreshToken::factory()->create(['user_id' => $user->id, 'revoked_at' => null]);
    $a->forceFill(['replaced_by_id' => $b->id])->save();
    $b->forceFill(['replaced_by_id' => $a->id])->save();

    $logs = captureLogs();

    // findOrFail rather than ->fresh(): fresh() is nullable, and a null here would be a
    // seeding bug, not something this test should carry a branch for.
    $revoked = app(RefreshTokenRotator::class)->revokeChainFrom(RefreshToken::query()->findOrFail($a->id));

    // Both rows revoked, the loop stopped, and the request did not hang.
    expect($revoked)->toBe(2);

    $exhausted = array_values(array_filter(
        capturedLogRecords($logs),
        fn (array $record): bool => $record['message'] === 'security.refresh_token.chain_walk_exhausted',
    ));

    expect($exhausted)->toHaveCount(1);
    expect($exhausted[0]['level'])->toBe('WARNING');
    expect($exhausted[0]['context']['user_id'] ?? null)->toBe($user->id);
    // No token material, ever: row ids and counts only.
    expect(array_keys($exhausted[0]['context']))
        ->toEqualCanonicalizing(['event', 'user_id', 'stopped_at_refresh_token_id', 'revoked']);
});

it('logs a detected replay, with the chain size and no token material', function (): void {
    [$user, $tokenA] = signIn();

    $tokenB = postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->assertOk()->json('data.refresh_token');
    postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenB])->assertOk();

    $logs = captureLogs();

    postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->assertUnauthorized();

    $replays = array_values(array_filter(
        capturedLogRecords($logs),
        fn (array $record): bool => $record['message'] === 'security.refresh_token.replay_detected',
    ));

    expect($replays)->toHaveCount(1);
    expect($replays[0]['level'])->toBe('WARNING');
    expect($replays[0]['context']['user_id'] ?? null)->toBe($user->id);
    // Only the live tail was unrevoked, so one row changed state - the number is the point of
    // reading revokeChainFrom()'s return value rather than discarding it.
    expect($replays[0]['context']['revoked_refresh_tokens'] ?? null)->toBe(1);
    expect(array_keys($replays[0]['context']))
        ->toEqualCanonicalizing(['event', 'user_id', 'replayed_refresh_token_id', 'revoked_refresh_tokens']);
});

it('records nothing for a refusal that is not a replay', function (): void {
    // A revoked-but-unrotated token is rejected and nothing else happens (see RotationPolicy),
    // so there is no security event to record. Without this, the log line above would fire on
    // every ordinary logout-then-retry and be filtered out of anyone's alerting within a week.
    [, $refresh, $access] = signIn();

    \Pest\Laravel\withToken($access)->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])->assertNoContent();

    $logs = captureLogs();

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
    postJson('/api/v1/auth/refresh', ['refresh_token' => bin2hex(random_bytes(32))])->assertUnauthorized();

    // Filtered to the `security.` prefix, not asserted empty: Laravel's exception handler
    // reports every InvalidRefreshToken through the same channel at ERROR level, even though
    // withExceptions() renders it as a 401. That is pre-existing noise with no token material
    // in it; what must be absent here is a SECURITY event, because nothing security-relevant
    // happened.
    $security = array_values(array_filter(
        capturedLogRecords($logs),
        fn (array $record): bool => str_starts_with($record['message'], 'security.'),
    ));

    expect($security)->toBe([]);
});
