<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Plans\InviteRepository;
use App\Application\Plans\PlanActivityRepository;
use App\Application\Plans\PlanMemberRepository;
use App\Application\Plans\PlanRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Http\Support\EmailInput;
use App\Infrastructure\Auth\EmailVerificationLink;
use App\Infrastructure\Notifications\Mail\ResetPasswordMail;
use App\Infrastructure\Notifications\Mail\VerifyEmailMail;
use App\Infrastructure\OpenApi\PasswordRuleTransformer;
use App\Infrastructure\Outbox\OutboxHandlers;
use App\Infrastructure\Persistence\Eloquent\EloquentInviteRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanActivityRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanMemberRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanRepository;
use App\Models\PersonalAccessToken;
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
        });
    }
}
