<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Domain\Users\Email;
use App\Infrastructure\Outbox\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
    }
}
