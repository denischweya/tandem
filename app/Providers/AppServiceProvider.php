<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Http\Support\EmailInput;
use App\Infrastructure\Auth\EmailVerificationLink;
use App\Infrastructure\Notifications\Mail\ResetPasswordMail;
use App\Infrastructure\Notifications\Mail\VerifyEmailMail;
use App\Infrastructure\OpenApi\PasswordRuleTransformer;
use App\Infrastructure\Outbox\OutboxHandlers;
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
        // rule ever gets a chance to reject it with a 422 - the same reachable 500 Task 4
        // found and fixed for the login limiter.
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
        });
    }
}
