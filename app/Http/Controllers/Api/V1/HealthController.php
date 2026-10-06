<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController
{
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): bool => DB::connection()->getPdo() !== null),
            'redis' => $this->check(fn (): bool => Redis::connection()->ping() !== false),
            'queue' => $this->check(fn (): bool => Redis::connection()->client()->llen('queues:default') !== false),
        ];

        $ok = ! in_array(false, $checks, true);

        return new JsonResponse(
            ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks],
            $ok ? 200 : 503,
        );
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            // A connection failure must become `false`, never a message that could
            // contain a host, port or credential: see the no-leak test, which asserts
            // that infrastructure detail never appears in the readiness response
            // (spec §114). Narrowing this catch or logging the exception would risk
            // exactly that leak, so it stays broad and silent by design.
            return false;
        }
    }
}
