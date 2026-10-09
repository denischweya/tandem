<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum events to claim in one pass}';

    protected $description = 'Publish pending outbox events (§85).';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $summary = $dispatcher->dispatchPending((int) $this->option('limit'));

        $this->line(json_encode([
            'tool' => 'outbox',
            'published' => $summary->published,
            'failed' => $summary->failed,
        ], JSON_THROW_ON_ERROR));

        // Exit 0 even with failures: a failed event stays pending and is retried on the next
        // pass, which is the designed behaviour, not a reason for the scheduler to alarm.
        return self::SUCCESS;
    }
}
