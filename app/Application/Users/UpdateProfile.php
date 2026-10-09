<?php

declare(strict_types=1);

namespace App\Application\Users;

use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Models\User;

final readonly class UpdateProfile
{
    /**
     * Each parameter is nullable and null means "not supplied", which is what makes this a
     * PATCH rather than a PUT. No field in a profile has "cleared" as a meaningful state, so
     * nothing is lost by that reading.
     */
    public function execute(User $user, ?DisplayName $displayName, ?TimeZoneId $timezone, ?string $locale): User
    {
        $profile = $user->profile;

        if ($displayName !== null) {
            $profile->display_name = $displayName->toString();
        }

        if ($timezone !== null) {
            $profile->timezone = $timezone->toString();
        }

        if ($locale !== null) {
            $profile->locale = $locale;
        }

        $profile->save();

        return $user->refresh();
    }
}
