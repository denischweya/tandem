<?php

declare(strict_types=1);

use App\Application\Auth\InvalidCredentials;
use App\Application\Auth\InvalidPasswordResetToken;
use App\Application\Auth\InvalidRefreshToken;
use App\Application\Plans\ActorEmailNotVerified;
use App\Application\Plans\OwnerCannotLeave;
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
 * The Plan surface's own control-flow exceptions, on the same argument as the auth ones above.
 * Each happens at a rate that scales with traffic rather than with bugs: a non-member probing a
 * Plan id (PlanNotVisible), an owner naming a {user} who is no longer an active member
 * (PlanMemberNotFound), a client acting on a Plan that was archived while its screen was open
 * (PlanNotEditable), an owner tapping "leave" on their own Plan (OwnerCannotLeave), and an
 * unverified actor removing another member (ActorEmailNotVerified) - the last being the one
 * `verified` refusal in this API raised from the Application layer rather than by
 * EnsureEmailIsVerified, which Laravel never reports either.
 *
 * Named rather than counted: the previous wording said "the three expected Plan refusals" in
 * both the name and the assertion message, and this task's two entries falsified both. A count
 * of the list below it decays on the next task's entry; the list itself does not.
 *
 * Exercises report() directly rather than an HTTP round trip, exactly as the block above does:
 * the behaviour under test is bootstrap/app.php's dontReport() list and the container-bound
 * ExceptionHandler that reads it.
 *
 * Known weakness, disclosed rather than worked around: every exception here is reported into ONE
 * captured-log assertion, so the expectation's own message cannot say which dontReport() entry
 * went missing, and each exception added here makes that worse. What rescues it is the DIFF
 * rather than the message, measured by removing PlanMemberNotFound::class from the list: the
 * captured record's `message` is the EMPTY STRING, because a no-argument RuntimeException
 * subclass carries none - it is the record's `context.exception` entry, which PHPUnit's exporter
 * prints as "App\Application\Plans\PlanMemberNotFound Object #NNNN (...)", that names the class.
 * Re-measured on OwnerCannotLeave by this task's break step, with the same result. So a red here
 * is diagnosable, but only by reading the exported object and not the assertion. Splitting this
 * into one block per exception would fix that properly and is still not any one task's.
 */
it('does not report the Plan refusals that are expected control flow', function (): void {
    $handler = app(ExceptionHandler::class);

    $logs = captureLogs();

    $handler->report(new PlanNotVisible);
    $handler->report(new PlanMemberNotFound);
    $handler->report(new PlanNotEditable);
    $handler->report(new OwnerCannotLeave);
    $handler->report(new ActorEmailNotVerified);

    expect(capturedLogRecords($logs))->toBe(
        [],
        'PlanNotVisible, PlanMemberNotFound, PlanNotEditable, OwnerCannotLeave and ActorEmailNotVerified must every one be in dontReport()',
    );
});
