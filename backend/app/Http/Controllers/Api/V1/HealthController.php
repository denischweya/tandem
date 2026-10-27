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
            // Each probe performs a real round-trip and returns true, rather than comparing
            // a return value against null or false. PHPStan level 8 rejects those comparisons
            // as always-true, and a type assertion would not prove the service is reachable
            // anyway. If the service is down, the call throws and check() returns false.
            'database' => $this->check(function (): bool {
                DB::connection()->select('select 1');

                return true;
            }),
            'redis' => $this->check(function (): bool {
                Redis::connection()->ping();

                return true;
            }),
            'queue' => $this->check(function (): bool {
                Redis::connection()->command('llen', ['queues:default']);

                return true;
            }),
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
