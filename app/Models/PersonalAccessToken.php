<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's own model assumes an auto-incrementing integer key. The
 * personal_access_tokens migration created `id` as a uuid primary key and `tokenable` as
 * uuidMorphs, because users.id is a uuid and the stub's unsigned bigint tokenable_id could
 * never reference it. HasUuids supplies the key generation Sanctum's model does not.
 *
 * Sanctum::findToken() splits the plaintext on "|" and calls static::find($id), which
 * works unchanged with a uuid key - no override is needed beyond the trait.
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;
}
