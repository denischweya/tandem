<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Domain\Users\Email;
use App\Infrastructure\Outbox\OutboxWriter;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class Register
{
    public function __construct(private OutboxWriter $outbox) {}

    public function execute(
        Email $email,
        DisplayName $displayName,
        TimeZoneId $timezone,
        string $locale,
        string $password,
    ): User {
        try {
            return DB::transaction(function () use ($email, $displayName, $timezone, $locale, $password): User {
                // Assigned, not mass-assigned: User declares #[Fillable(['email'])], and that
                // narrow list is deliberate - widening it to admit password_hash would make every
                // future ->fill() a potential credential write. The `hashed` cast is idempotent
                // (Hash::isHashed), so hashing here explicitly is safe and keeps the one place
                // that turns a plaintext password into a stored hash visible in the use case.
                $user = new User;
                $user->email = $email->toString();
                $user->password_hash = Hash::make($password);
                $user->save();

                $user->profile()->create([
                    'display_name' => $displayName->toString(),
                    'timezone' => $timezone->toString(),
                    'locale' => $locale,
                ]);

                // Inside the transaction, deliberately: if the profile write fails, no email is
                // ever sent, because the event never commits (§85).
                $this->outbox->write('User', $user->id, 'user.registered', ['user_id' => $user->id]);

                return $user->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // RegisterRequest's own `unique:users,email` rule already checked this address and
            // passed, which means the only way to still land here is a race: two concurrent
            // registrations for the same case-folded address can both pass that rule's SELECT
            // and then collide on the real users_email_unique constraint when their INSERTs
            // run. That is a TOCTOU gap inherent to Laravel's `unique` rule, not a defect in
            // normalization - this exists even when normalization works correctly.
            //
            // Caught around the DB::transaction() call, not inside its closure: the closure
            // already rolls back on any Throwable before this catch ever runs (Laravel's
            // transaction wrapper does that internally and rethrows), so by the time control
            // reaches here the rollback has already happened and $e is the real exception,
            // not a rollback artifact - there is nothing left to undo from this catch block.
            //
            // Scoped to the email column specifically, not to every UniqueConstraintViolationException:
            // `users` has exactly one unique constraint (email), but re-throwing anything that
            // does not name it keeps a genuine anomaly - e.g. a user_id collision surfacing from
            // the profile insert in the same transaction - a 500, rather than quietly mislabelling
            // it as "this email is taken" to the client. A plain QueryException (a connection
            // failure, a NOT NULL violation) is a different exception type entirely and is never
            // caught here, so it still surfaces as the 500 it actually is.
            if (! in_array('email', $e->columns, true)) {
                throw $e;
            }

            // Same message `unique:users,email` would have produced, so the race and the
            // ordinary duplicate-email rejection are indistinguishable to the client.
            throw ValidationException::withMessages([
                'email' => [trans('validation.unique', ['attribute' => 'email'])],
            ]);
        }
    }
}
