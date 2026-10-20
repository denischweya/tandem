<?php

declare(strict_types=1);

use App\Application\Auth\InvalidCredentials;
use App\Application\Auth\InvalidPasswordResetToken;
use App\Application\Auth\InvalidRefreshToken;
use App\Application\Plans\PlanMemberNotFound;
use App\Application\Plans\PlanNotEditable;
use App\Application\Plans\PlanNotVisible;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * bootstrap/app.php registers render() handlers for these three exceptions - one body each
 * for every wrong password, every stale/replayed refresh token and every dead password-reset
 * link - but, before this test existed, called dontReport() for none of them. That meant
 * every one of those expected, traffic-scaling control-flow outcomes on a public auth surface
 * was reported at ERROR level on the same channel this wave's Log::warning() security lines
 * (replay detection, exhausted chain walks, ...) use. See
 * tests/Feature/Auth/RefreshChainTest.php's "records nothing for a refusal that is not a
 * replay", which had to filter captured logs down to the `security.` prefix to work around
 * exactly this noise.
 *
 * Exercises report() directly rather than a real HTTP round trip: the behaviour under test is
 * bootstrap/app.php's dontReport() list, and the real, container-bound ExceptionHandler is
 * what reads it - report() needs no route to actually throw from to prove that.
 *
 * The second half is the one that matters most: a dontReport() list is exactly the kind of
 * change that silences more than intended, so this also proves a genuinely unexpected
 * exception is still reported, at ERROR, same as before.
 */
it('does not report the three expected auth failures, but still reports a genuine error', function (): void {
    $handler = app(ExceptionHandler::class);

    $logs = captureLogs();

    $handler->report(new InvalidCredentials);
    $handler->report(new InvalidRefreshToken);
    $handler->report(new InvalidPasswordResetToken);

    expect(capturedLogRecords($logs))->toBe([]);

    $handler->report(new RuntimeException('a genuinely unexpected failure'));

    $unexpected = capturedLogRecords($logs);

    expect($unexpected)->toHaveCount(1);
    expect($unexpected[0]['level'])->toBe('ERROR');
    expect($unexpected[0]['message'])->toBe('a genuinely unexpected failure');
});

/**
 * The Plan surface's own control-flow exceptions, on the same argument as the auth ones above:
 * a non-member probing a Plan id (PlanNotVisible), a client acting on a Plan that was archived
 * while its screen was open (PlanNotEditable), and an owner naming a {user} who is no longer an
 * active member (PlanMemberNotFound) all happen at a rate that scales with traffic rather than
 * with bugs.
 *
 * Exercises report() directly rather than an HTTP round trip, exactly as the block above does:
 * the behaviour under test is bootstrap/app.php's dontReport() list and the container-bound
 * ExceptionHandler that reads it.
 *
 * Known weakness, disclosed rather than worked around: every exception here is reported into
 * ONE captured-log assertion, so a red says "something was reported" and never which
 * dontReport() entry went missing - the diff carries the log record's own message, which names
 * the class, but the expectation does not. Splitting this into one block per exception would fix
 * that and is not this task's to do; what is true today is that the red names the class in its
 * diff and nothing else, and that each exception added here makes the aggregation worse.
 */
it('does not report any of the three expected Plan refusals', function (): void {
    $handler = app(ExceptionHandler::class);

    $logs = captureLogs();

    $handler->report(new PlanNotVisible);
    $handler->report(new PlanNotEditable);
    $handler->report(new PlanMemberNotFound);

    expect(capturedLogRecords($logs))->toBe(
        [],
        'PlanNotVisible, PlanNotEditable and PlanMemberNotFound must all three be in dontReport()',
    );
});
