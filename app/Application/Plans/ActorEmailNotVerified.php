<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * Spec §7.5's `verified` gate, enforced in the Application layer for exactly one route, because
 * that route cannot carry the middleware.
 *
 * DELETE /plans/{plan}/members/{user} is both LeavePlan and RemoveMember (spec §8.3). §7.5
 * gates "removing another member" and deliberately does not gate leaving, so the two halves of
 * one URI want opposite answers - and `verified` is route middleware, which runs before the
 * controller and therefore before anything knows which half a request is. RemoveMember throws
 * this instead, first, before any other refusal, so that the order matches the routes where
 * EnsureEmailIsVerified does the work.
 *
 * The body its render() closure publishes is byte-for-byte EnsureEmailIsVerified's own, so a
 * client cannot tell the two mechanisms apart. Measured, from
 * vendor/laravel/framework/src/Illuminate/Auth/Middleware/EnsureEmailIsVerified.php, which
 * calls abort(403, 'Your email address is not verified.'). Note what this rules out: a bare
 * AuthorizationException here would publish "This action is unauthorized." instead, which is
 * the body a ROLE refusal on this same route carries - so the two refusals would become
 * indistinguishable to a client that can act on only one of them.
 *
 * Disclosed, because nothing else in the suite covers it: VerifiedGateTest builds its gated/
 * ungated partition from the real route table, and this route correctly carries no `verified`,
 * so that gate is green whether or not this check exists. The refusal is held open by
 * LeaveAndRemoveMemberTest's "refuses an unverified actor removing another member, before it
 * looks the Plan up" and by nothing else.
 */
final class ActorEmailNotVerified extends RuntimeException {}
