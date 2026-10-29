<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Plans\InviteRepository;
use App\Application\Plans\PlanActivityRepository;
use App\Application\Plans\PlanMemberRepository;
use App\Application\Plans\PlanMessageRepository;
use App\Application\Plans\PlanRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Http\Support\EmailInput;
use App\Infrastructure\Auth\EmailVerificationLink;
use App\Infrastructure\Notifications\Mail\PlanInviteMail;
use App\Infrastructure\Notifications\Mail\ResetPasswordMail;
use App\Infrastructure\Notifications\Mail\VerifyEmailMail;
use App\Infrastructure\OpenApi\PasswordRuleTransformer;
use App\Infrastructure\Outbox\OutboxHandlers;
use App\Infrastructure\Persistence\Eloquent\EloquentInviteRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanActivityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanMemberRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanMessageRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanRepository;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(PlanRepository::class, EloquentPlanRepository::class);
        $this->app->bind(PlanMemberRepository::class, EloquentPlanMemberRepository::class);
        $this->app->bind(InviteRepository::class, EloquentInviteRepository::class);
        $this->app->bind(PlanActivityRepository::class, EloquentPlanActivityRepository::class);
        $this->app->bind(PlanMessageRepository::class, EloquentPlanMessageRepository::class);
        $this->app->singleton(OutboxHandlers::class);

        // Scramble documents the string rule `min:12` but ships nothing that understands
        // Illuminate's Password rule object, so Password::min(12) published no minLength at
        // all - a documented contract weaker than the enforced one. See the transformer.
        Scramble::configure()->withRuleTransformers(PasswordRuleTransformer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(5)->by($request->ip() ?? 'unknown'));

        // §8.2: 3 per hour per email, keyed on the email rather than the IP. Unlike login,
        // this row counts requests, not failures, so route-level throttle: (on
        // auth.forgot-password) is correct - see routes/api/v1/auth.php.
        //
        // is_string() guarded, not a bare (string) cast: an array value for `email` passes
        // route dispatch and would otherwise emit an E_WARNING that becomes an
        // ErrorException inside this closure, before ForgotPasswordRequest's own 'string'
        // rule ever gets a chance to reject it with a 422 - the same reachable 500 the login
        // limiter had while it still read the request from middleware, before it moved inside
        // Login::execute().
        //
        // EmailInput::normalize() here is the same call ForgotPasswordRequest's
        // NormalizesEmailInput trait makes. The 3-per-hour-per-email limit is only
        // un-bypassable by casing because this bucket key and that request's validated
        // email agree - two independent strtolower(trim(...)) copies would make that
        // agreement coincidental, and a future edit to one could silently reopen the limit
        // to a casing bypass.
        RateLimiter::for('auth-forgot-password', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perHour(3)->by('email:'.EmailInput::normalize(is_string($email) ? $email : ''));
        });

        // §13.1: 30 per hour per user, counting requests rather than outcomes - creating thirty
        // Plans an hour is the thing being limited whether or not each one succeeds, which is
        // spec §8.2's own reasoning about registration. So this is route-level throttle:
        // middleware and not an in-use-case limiter like LoginRateLimiter, and its 429 carries
        // the full four-header set.
        //
        // Keyed on the user id. That is the whole claim, and it is not observable from a status
        // code: a limiter keyed on the IP answers 429 exactly like one keyed on the user. The
        // test that can see it is "limits one user to thirty an hour and leaves a second user on
        // the same IP alone" in tests/Feature/Plans/CreatePlanTest.php - every request in the
        // suite arrives with REMOTE_ADDR = 127.0.0.1, so under an IP key the second user shares
        // the first user's bucket and that test must fail.
        //
        // $request->user() is non-null here because auth:sanctum sorts before ThrottleRequests
        // in the Kernel's $middlewarePriority (measured: 0 Authenticate:sanctum,
        // 1 ThrottleRequests). The ip() fallback is unreachable on this route and is kept so
        // that Limit::by() always receives a string, and so that a future unauthenticated
        // caller cannot land every request in one shared bucket.
        //
        // ?->getAuthIdentifier() rather than ?->id: measured, not merely the documented
        // fallback for an undefined-property report. phpstan analyse --level=8 flags
        // ?->id ?? ... with nullsafe.neverNull - PHPStan's IssetCheck::doCheck() treats a
        // nullsafe PROPERTY fetch that bottoms out as the "leaf" of a ?? chain's isset
        // resolution as unconditionally unnecessary, independent of whether the object is
        // actually nullable (reproduced on a plain, unrelated class with a declared-nullable
        // parameter, so it is not specific to $request->user() or to Eloquent). A nullsafe
        // METHOD call in the same position does not hit that code path. getAuthIdentifier()
        // is Authenticatable's own contract method and returns $this->{getAuthIdentifierName()},
        // which for App\Models\User is $this->id (no override of getKeyName()) - the same uuid.
        //
        // And PHPStan is RIGHT to flag it, not merely consistent: `??` already applies isset
        // semantics to its whole left operand, so `$request->user()->id ?? ...` and
        // `$request->user()?->id ?? ...` are the same value AND the same lack of warning for a
        // null object - measured (`$f()->b ?? 'fallback'` for a function returning null emits
        // no "Attempt to read property" warning). The nullsafe buys nothing `??` was not
        // already providing for THIS combination; it is the method-call form that is kept,
        // not the property-fetch form restored, and only because it keeps the nullability of
        // $request->user() visible in the source once the property access no longer carries
        // its own `?->` to say so.
        //
        // The ip()/unknown fallback chain is unreachable on this route for the same reason
        // $request->user() is non-null above - recorded for Task 36, which owns the limiter
        // registry: RateLimiter::for() registers 'plans-create' globally, so Limit::by() must
        // always receive a string regardless of which route ever calls into this closure, and
        // the fallback is what guarantees that rather than something this route currently uses.
        RateLimiter::for('plans-create', fn (Request $request) => Limit::perHour(30)->by($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown'));

        // No named 'auth-login' limiter here (and so no throttle:auth-login on the route):
        // spec §8.2 counts login's two limits by failed attempt, not by request, which
        // middleware cannot do - it decides before the controller runs, before this
        // request's own outcome is known. App\Infrastructure\Auth\LoginRateLimiter applies
        // both limits from inside Login::execute() instead. A previous version of this
        // closure read $request->input('email') directly and crashed with a 500 on a
        // non-string email (an array passes Laravel's route dispatch but fails `(string)`
        // cast with an E_WARNING the kernel promotes to an ErrorException, uncaught here)
        // before LoginRequest's own 'string' rule ever got a chance to reject it with a 422
        // - removing the closure removes that hazard along with the dead code.
        // GET /invites/{token} is the only unauthenticated read in the API that touches Plan
        // data. The limit does NOT protect the token: 256 bits is not guessable, which is the
        // same reason /auth/reset-password carries no throttle at all ("the token itself is the
        // credential"). What it bounds is scraping with a harvested list of links, and a looping
        // client. 60/hour/IP leaves a household behind one NAT clicking invite links
        // comfortably clear - a person opens an invite link once, maybe twice - while capping a
        // single host at a rate no legitimate client reaches.
        //
        // Keyed on IP rather than on the token, because a per-token key would let one host walk
        // a list of tokens unimpeded, which is the only attack shape that matters here. This IS
        // observable from a test, and is observed: every OTHER request in this file arrives
        // with the test client's default REMOTE_ADDR, which would leave an IP key
        // indistinguishable from a constant one, but
        // PreviewInviteTest::it('keys the invite-lookup limit on the IP and not on a shared
        // bucket') drives two distinct REMOTE_ADDR values through withServerVariables(),
        // exhausts one, and asserts the other is untouched - the property this limiter exists
        // to provide. That test also pins the cache key SHAPE (md5('invite-lookup'.$ip)), the
        // same way CreatePlanTest pins md5('plans-create'.$userId) for the per-user limiter.
        // The count (60/hour) is what the OTHER PreviewInviteTest case pins.
        RateLimiter::for('invite-lookup', fn (Request $request) => Limit::perHour(60)->by($request->ip() ?? 'unknown'));

        // §13.2. TWO limits from ONE named limiter, because RateLimiter::for() accepts an array
        // of Limits - handleRequestUsingNamedLimiter() does Collection::wrap($limiterResponse)
        // ->map(...) - which is simpler than two stacked throttle: entries and gives the
        // arithmetic below for free.
        //
        // The arithmetic is sound and measured: handleRequest() checks tooManyAttempts for EVERY
        // limit in one loop BEFORE hitting ANY limit in a second loop, so the 429 thrown by the
        // per-plan limit does not increment the per-user bucket. That is what lets the two
        // rate-limit tests in InvitePlanMemberTest exist without polluting each other.
        //
        // $request->user() is resolved here: Authenticate sorts before ThrottleRequests in
        // Laravel's default middleware priority, in ANY declaration order - measured by
        // reflecting the booted router, not inferred. The ?? $request->ip() fallback is
        // therefore unreachable on this route, and is kept only because user() is declared
        // nullable.
        //
        // The is_string() guard on the route parameter stays, but NOT because it protects
        // against a bound model: SubstituteBindings sorts AFTER ThrottleRequests, so
        // route('plan') is always a raw string here and the UrlRoutable branch is unreachable.
        // It is cheap defence on a method declared string|UrlRoutable|null, and it is the same
        // guard shape the forgot-password limiter needed for an expensive measured reason: an
        // array value for a request field passed route dispatch, and a bare (string) cast
        // emitted an E_WARNING the kernel promoted to an ErrorException INSIDE the closure - a
        // reachable 500 before any FormRequest rule could answer 422.
        // ?->getAuthIdentifier() rather than ?->id, for the same measured PHPStan reason the
        // plans-create limiter above gives: level 8 flags a nullsafe PROPERTY fetch on the left
        // of ?? as nullsafe.neverNull ("unnecessary, use -> instead"), regardless of whether the
        // object is actually nullable, while a nullsafe METHOD call in the same position does
        // not trip it. getAuthIdentifier() returns the same uuid $this->id would.
        // §13 (chat): 30 per minute per user, counting requests rather than outcomes - the same
        // reasoning 'plans-create' above gives for a per-user rather than a per-Plan key: sending
        // thirty messages a minute is the thing being limited whether or not each one succeeds.
        // ?->getAuthIdentifier() rather than ?->id, for the same measured PHPStan reason
        // 'plans-create' and 'plan-invites' above give: level 8 flags a nullsafe PROPERTY fetch on
        // the left of ?? as nullsafe.neverNull, while a nullsafe METHOD call in the same position
        // does not. $request->user() is non-null on this route (auth:sanctum sorts before
        // ThrottleRequests), so the 'unknown' fallback is unreachable here and kept only because
        // RateLimiter::for() registers this limiter globally.
        RateLimiter::for('chat-send', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->getAuthIdentifier() ?? 'unknown'));

        RateLimiter::for('plan-invites', function (Request $request): array {
            $plan = $request->route('plan');

            return [
                Limit::perDay(50)->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown')),
                Limit::perHour(20)->by('plan:'.(is_string($plan) ? $plan : 'unknown')),
            ];
        });

        $this->app->resolving(OutboxHandlers::class, function (OutboxHandlers $handlers): void {
            $handlers->register('user.registered', function (array $payload): void {
                // The payload is decoded JSON, so every value is mixed. A user_id that is not
                // a string is a malformed event, not something to look up - and passing an
                // array to find() would return a Collection rather than one model.
                $userId = $payload['user_id'] ?? null;

                $user = is_string($userId)
                    ? User::query()->with('profile')->find($userId)
                    : null;

                if ($user === null) {
                    // Registered and then hard-deleted before the dispatcher ran. Nothing to send,
                    // and nothing wrong - returning normally publishes the event rather than
                    // retrying it forever.
                    return;
                }

                Mail::to($user->email)->send(new VerifyEmailMail(
                    $user->profile->display_name,
                    app(EmailVerificationLink::class)->for($user),
                ));
            });

            $handlers->register('password.reset_requested', function (array $payload): void {
                $userId = $payload['user_id'] ?? null;

                $user = is_string($userId)
                    ? User::query()->with('profile')->find($userId)
                    : null;

                if ($user === null) {
                    return;
                }

                // Decrypt what RequestPasswordReset encrypted. A malformed or foreign
                // payload throws DecryptException rather than silently mailing a broken
                // link, which the dispatcher records as a failed attempt and retries - the
                // correct outcome, so this deliberately does not catch it.
                $raw = $payload['token'] ?? null;
                $token = Crypt::decryptString(is_string($raw) ? $raw : '');

                $url = rtrim((string) config('app.web_url'), '/').'/reset-password?token='.urlencode($token)
                    .'&email='.urlencode($user->email);

                Mail::to($user->email)->send(new ResetPasswordMail($user->profile->display_name, $url, $token));
            });

            // Deliberately a no-op that publishes. The event exists so the calendar cycle can
            // hang §127's provider-token revocation off it without changing the use case, and
            // registering it now means the dispatcher publishes rather than reporting an
            // unhandled type forever. If this is ever given real work, the no-op comment is
            // the thing to delete.
            //
            // The event type is 'user.deleted' and nothing else. Reusing 'user.registered'
            // here would have replaced the verification email handler with this empty
            // closure; OutboxHandlers::register() now throws on a duplicate type rather than
            // leaving that hazard to a comment at one of three independent registration
            // sites.
            $handlers->register('user.deleted', function (array $payload): void {});

            // The FOURTH outbox event type ('user.registered', 'password.reset_requested' and
            // 'user.deleted' are already registered above). register() throws LogicException on
            // a duplicate rather than silently replacing an earlier handler, so the literal only
            // has to be new and a collision fails loudly at boot.
            $handlers->register('plan.invited', function (array $payload): void {
                // The payload is decoded JSON, so every value is mixed. An invite_id that is not
                // a string is a malformed event, not something to look up.
                $inviteId = $payload['invite_id'] ?? null;

                $invite = is_string($inviteId) ? PlanInvite::query()->find($inviteId) : null;

                // No invite row: its Plan was hard-deleted, which CASCADEs plan_invites away.
                // No address: §14.1 clause 2 nulls the email of an unaccepted invite addressed
                // to a user who has deleted their account, and honouring that deletion is the
                // whole point of reading the address here instead of copying it into the
                // payload. Either way there is nothing to send and nothing wrong - returning
                // normally publishes the event rather than retrying it for ever.
                if ($invite === null || $invite->email === null) {
                    return;
                }

                $plan = Plan::query()->find($invite->plan_id);

                if ($plan === null) {
                    return;
                }

                $inviter = $invite->invited_by === null
                    ? null
                    : User::query()->with('profile')->find($invite->invited_by);

                // An invitation that cannot name its sender is not sent. The only way to get
                // here is an inviter whose account went away between the write and the
                // dispatch, and §14.1 clause 1 revokes a departing user's outstanding invites
                // anyway, so this invite is already finished - mailing a link for it would be
                // worse than sending nothing.
                if ($inviter === null) {
                    return;
                }

                // Decrypt what InvitePlanMember encrypted. A malformed or foreign payload throws
                // DecryptException rather than silently mailing a broken link, which the
                // dispatcher records as a failed attempt and retries - the correct outcome, so
                // this deliberately does not catch it.
                $raw = $payload['token'] ?? null;
                $token = Crypt::decryptString(is_string($raw) ? $raw : '');

                // Blueprint §132's https://app.example.com/invite/{token}, exactly, built the
                // way password.reset_requested's handler already builds its one. The API NEVER
                // builds this URL - only this handler does, for the mail - because the 201
                // returns the bare token and lets each client construct the link its own
                // platform needs (app://invite/{token} on mobile, this form on web).
                $url = rtrim((string) config('app.web_url'), '/').'/invite/'.$token;

                Mail::to($invite->email)->send(new PlanInviteMail(
                    $inviter->profile->display_name,
                    $plan->title,
                    $url,
                    $token,
                ));
            });
        });
    }
}
