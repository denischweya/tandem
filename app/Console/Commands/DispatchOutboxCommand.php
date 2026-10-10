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
            // Rows past OutboxDispatcher::MAX_ATTEMPTS, which this pass deliberately did not
            // claim. Reported separately from `failed` because the two have opposite
            // meanings: `failed` self-heals on the next pass, `abandoned` never does.
            'abandoned' => $summary->abandoned,
        ], JSON_THROW_ON_ERROR));

        // Exit 0 even with failures: a failed event stays pending and is retried on the next
        // pass, which is the designed behaviour, not a reason for the scheduler to alarm.
        // This stays true of `abandoned` too, even though that one does NOT self-heal - the
        // row crossing the threshold already wrote one Log::warning carrying its id and its
        // last error (see OutboxDispatcher::recordFailure()), which is the alertable signal.
        // Failing the command instead would make every subsequent minute fail as well, for a
        // condition one line already reported, and would couple "a handler is broken" to
        // "this process did not work" - the distinction routes/console.php draws between this
        // command and users:purge-deleted.
        return self::SUCCESS;
    }
}
