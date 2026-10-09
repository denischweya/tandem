<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Infrastructure\Auth\EmailVerificationLink;
use App\Infrastructure\Notifications\Mail\VerifyEmailMail;
use App\Infrastructure\OpenApi\PasswordRuleTransformer;
use App\Infrastructure\Outbox\OutboxHandlers;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        });
    }
}
